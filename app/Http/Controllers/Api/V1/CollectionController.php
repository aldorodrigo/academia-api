<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\CashCollectionAccess;
use App\Actions\Billing\CollectCashPayment;
use App\Actions\Billing\EarlyPaymentDiscount;
use App\Actions\Billing\PaymentReportAccess;
use App\Actions\Billing\ReceiptNotice;
use App\Actions\Billing\RegisterPayment;
use App\Actions\Billing\SubmitPaymentReport;
use App\Enums\ChargeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChargeResource;
use App\Http\Resources\Api\V1\PaymentReportResource;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Charge;
use App\Models\Guardian;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\User;
use App\Support\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Cobro en efectivo desde la app (permiso "Cobrar en efectivo desde la app"): a quién puede
 * cobrar, qué debe su familia y registrar el cobro en su caja.
 */
class CollectionController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function students(Request $request): JsonResponse
    {
        $user = $this->authorizeCollect($request);
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) $request->input('search'));

        $students = CashCollectionAccess::students($user)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('document', $search)))
            ->with(['family', 'currentEnrollments.group', 'media'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(200)
            ->get();

        // Lo que debe cada familia hoy (sin las próximas), con una sola consulta.
        $charges = Charge::query()->notVoided()
            ->whereHas('student', fn (Builder $query) => $query->whereIn('family_id', $students->pluck('family_id')->filter()->unique()))
            ->with(['student', 'allocations.payment', 'organization', 'season'])
            ->get()
            ->reject(fn (Charge $charge) => $charge->isUpcoming())
            ->groupBy(fn (Charge $charge) => $charge->student->family_id);

        return response()->json([
            'data' => $students->map(function (Student $student) use ($charges) {
                $family = $student->family_id === null ? collect() : $charges->get($student->family_id, collect());

                return [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'photo_url' => $student->photoUrl(),
                    'groups' => $student->currentEnrollments->pluck('group.name')->filter()->unique()->values(),
                    'family' => $student->family?->name,
                    'due_now' => (int) $family->sum(fn (Charge $charge) => $charge->pendingAmount()),
                    'overdue' => (int) $family->filter(fn (Charge $charge) => $charge->status() === ChargeStatus::Overdue)
                        ->sum(fn (Charge $charge) => $charge->pendingAmount()),
                ];
            })->values(),
        ]);
    }

    public function show(Request $request, int $student, RegisterPayment $register, EarlyPaymentDiscount $earlyPayment): JsonResponse
    {
        $user = $this->authorizeCollect($request);
        $student = $this->findStudent($user, $student);
        $family = $student->family;
        $today = $this->current->get()->today();

        $charges = $family === null ? collect() : $register->pendingCharges($family)
            ->load(['feeConcept', 'group', 'season', 'adjustments']);
        $underReview = $family === null ? [] : PaymentReport::query()->pending()->where('family_id', $family->id)
            ->pluck('charge_ids')->flatten()->map(fn ($id) => (int) $id)->all();

        return response()->json([
            'data' => [
                'student' => ['id' => $student->id, 'full_name' => $student->full_name],
                'family' => $family === null ? null : [
                    'id' => $family->id,
                    'name' => $family->name,
                    'students' => $family->students()->orderBy('birth_date')->pluck('first_name'),
                ],
                'guardians' => $family === null ? [] : Guardian::query()->where('family_id', $family->id)->get()
                    ->map(fn (Guardian $guardian) => ['id' => $guardian->id, 'full_name' => $guardian->full_name])->values(),
                'credit' => $family?->credit() ?? 0,
                'charges' => $charges->map(function (Charge $charge) use ($request, $earlyPayment, $today, $underReview) {
                    $pending = $charge->pendingAmount();
                    $early = $earlyPayment->for($charge, $today, $pending);

                    return [
                        ...(new ChargeResource($charge))->toArray($request),
                        'settle_amount' => $pending - ($early['amount'] ?? 0),
                        'early_payment' => $early === null ? null : ['amount' => $early['amount'], 'label' => $early['label']],
                        'under_review' => in_array($charge->id, $underReview, true),
                    ];
                })->values(),
                'cash_box' => $this->box(MoneyAccount::cashBoxOf($user, $this->current->get())),
                // Para registrar una transferencia que la familia le mandó: a qué cuenta y si queda aprobada al toque.
                'transfer_accounts' => PaymentReportAccess::clubTransferAccounts()
                    ->map(fn (MoneyAccount $account) => ['id' => $account->id, 'name' => $account->name])->values(),
                'approves_transfers' => PaymentReportAccess::canReview($user, $this->current->get()),
                // Si no la aprueba al registrarla: quiénes la aprueban (la app los nombra).
                'confirmers' => PaymentReportAccess::confirmers($this->current->get(), $user),
                // Cobra directo a la Caja: el efectivo entra en una cuenta del club (por defecto la Caja).
                ...self::collectTarget($user, $this->current->get()),
            ],
        ]);
    }

    /**
     * Registra la transferencia que la familia le mandó (captura o PDF): aprobada con recibo si quien la registra
     * valida comprobantes; si no, en revisión.
     */
    public function transfer(Request $request, SubmitPaymentReport $submit): JsonResponse
    {
        $user = $this->authorizeCollect($request);
        $today = $this->current->get()->today()->toDateString();
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'paid_on' => ['required', 'date', "before_or_equal:{$today}"],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:5120'],
            'charge_ids' => ['nullable', 'array', 'max:50'],
            'charge_ids.*' => ['integer', 'distinct'],
            'money_account_id' => ['nullable', 'integer'],
            'guardian_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.required' => 'Ingresá el monto de la transferencia.',
            'amount.min' => 'El monto tiene que ser mayor a cero.',
            'paid_on.before_or_equal' => 'La fecha de la transferencia no puede ser futura.',
            'proof.required' => 'Adjuntá la captura o el PDF de la transferencia.',
            'proof.mimes' => 'El comprobante tiene que ser una foto o un PDF.',
            'proof.max' => 'El comprobante pesa más de 5 MB.',
        ]);

        $student = $this->findStudent($user, (int) $data['student_id']);
        $guardian = isset($data['guardian_id']) ? Guardian::query()->find($data['guardian_id']) : null;
        if (isset($data['guardian_id']) && $guardian === null) {
            throw ValidationException::withMessages(['guardian_id' => 'Elegí un tutor de la familia.']);
        }

        $report = $submit->onBehalf(
            $user,
            $student,
            (int) $data['amount'],
            CarbonImmutable::parse($data['paid_on']),
            $request->file('proof'),
            array_map('intval', $data['charge_ids'] ?? []),
            isset($data['money_account_id']) ? (int) $data['money_account_id'] : null,
            $guardian,
            $data['reference'] ?? null,
            $data['notes'] ?? null,
        );

        $report->load(['moneyAccount', 'payment', 'user']);

        return response()->json([
            'data' => (new PaymentReportResource($report))->toArray($request),
            // Aprobada al instante: a quién le llega el recibo (como en el cobro en efectivo).
            'notice' => $report->payment !== null ? ReceiptNotice::toArray($report->payment) : null,
            'message' => $report->payment !== null
                ? 'Transferencia registrada. Recibo N° '.$report->payment->receiptLabel().'.'
                : 'Transferencia registrada. Queda en revisión '
                    .PaymentReportAccess::untilConfirmed(PaymentReportAccess::confirmers($this->current->get(), $user), $this->current->get(), ['la apruebe', 'la aprueben']).'.',
        ], 201);
    }

    public function store(Request $request, CollectCashPayment $collect): JsonResponse
    {
        $user = $this->authorizeCollect($request);
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'charge_ids' => ['nullable', 'array', 'max:50'],
            'charge_ids.*' => ['integer', 'distinct'],
            'guardian_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
            'request_id' => ['nullable', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'money_account_id' => ['nullable', 'integer'],
        ], [
            'amount.required' => 'Ingresá el monto que cobraste.',
            'amount.min' => 'El monto tiene que ser mayor a cero.',
        ]);

        $student = $this->findStudent($user, (int) $data['student_id']);
        $payer = isset($data['guardian_id']) ? Guardian::query()->find($data['guardian_id']) : null;
        abort_if(isset($data['guardian_id']) && $payer === null, 422, 'Elegí un tutor de la familia.');

        $payment = $collect->handle(
            $user,
            $student,
            (int) $data['amount'],
            array_map('intval', $data['charge_ids'] ?? []),
            $payer,
            $data['notes'] ?? null,
            $data['request_id'] ?? null,
            isset($data['money_account_id']) ? (int) $data['money_account_id'] : null,
        );
        $payment->load(['allocations.charge.student']);
        $applied = (int) $payment->originalAllocations()->sum('amount');

        return response()->json([
            'data' => [
                'payment' => (new PaymentResource($payment))->toArray($request),
                'applied' => $applied,
                'credit' => $payment->creditGenerated(),
                // Cobrando directo a la Caja no hay caja personal.
                'cash_box' => $payment->moneyAccount->isCashBox() ? $this->box($payment->moneyAccount) : null,
                'account' => ['id' => $payment->moneyAccount->id, 'name' => $payment->moneyAccount->name],
                'message' => 'Cobrado '.Money::pyg($payment->amount)->format().". Recibo N° {$payment->receiptLabel()}.",
                // A quién le llega el recibo y por dónde, de verdad; a quien no, WhatsApp con el link (30 días).
                'notice' => ReceiptNotice::toArray($payment),
            ],
        ], 201);
    }

    /**
     * `collects_to_org_cash`, `collect_accounts` y `default_collect_account_id` (vacío si rinde a su caja).
     *
     * @return array{collects_to_org_cash: bool, collect_accounts: list<array<string, mixed>>, default_collect_account_id: ?int}
     */
    public static function collectTarget(User $user, Organization $organization): array
    {
        $direct = CashCollectionAccess::collectsToOrgCash($user, $organization);

        return [
            'collects_to_org_cash' => $direct,
            'collect_accounts' => $direct ? CashCollectionAccess::collectAccounts()
                ->map(fn (MoneyAccount $account) => ['id' => $account->id, 'name' => $account->name, 'type' => $account->type->value])->values()->all() : [],
            'default_collect_account_id' => $direct ? CashCollectionAccess::orgCash()?->id : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function box(?MoneyAccount $box): ?array
    {
        return $box === null ? null : [
            'id' => $box->id,
            'name' => $box->name,
            'balance' => $box->balance(),
            'active' => $box->is_active,
        ];
    }

    private function findStudent(User $user, int $student): Student
    {
        $student = CashCollectionAccess::students($user)->with('family')->find($student);

        abort_if($student === null, 404, 'No encontramos a este alumno.');

        return $student;
    }

    private function authorizeCollect(Request $request): User
    {
        $user = $request->user();

        abort_unless(CashCollectionAccess::canCollect($user), 403, 'No tenés permiso para cobrar desde la app.');

        return $user;
    }
}
