<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\PaymentReportAccess;
use App\Actions\Billing\ReceiptNotice;
use App\Actions\Billing\ReviewPaymentReport;
use App\Actions\Billing\SubmitPaymentReport;
use App\Filament\Support\Terms;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymentReportResource;
use App\Http\Resources\Api\V1\ReviewPaymentReportResource;
use App\Models\PaymentReport;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Comprobantes de transferencia: el tutor los informa (o los retira mientras
 * están en revisión) y quien valida los aprueba o rechaza.
 */
class PaymentReportController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function store(Request $request, SubmitPaymentReport $submit): JsonResponse
    {
        $today = $this->current->get()->today()->toDateString();
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'paid_on' => ['required', 'date', "before_or_equal:{$today}"],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:5120'],
            'charge_ids' => ['nullable', 'array', 'max:50'],
            'charge_ids.*' => ['integer', 'distinct'],
            'money_account_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.required' => 'Ingresá el monto que transferiste.',
            'amount.min' => 'El monto tiene que ser mayor a cero.',
            'paid_on.before_or_equal' => 'La fecha de la transferencia no puede ser futura.',
            'proof.required' => 'Adjuntá el comprobante de la transferencia.',
            'proof.mimes' => 'El comprobante tiene que ser una foto o un PDF.',
            'proof.max' => 'El comprobante pesa más de 5 MB.',
        ]);

        $report = $submit->handle(
            $request->user(),
            (int) $data['amount'],
            CarbonImmutable::parse($data['paid_on']),
            $request->file('proof'),
            array_map('intval', $data['charge_ids'] ?? []),
            isset($data['money_account_id']) ? (int) $data['money_account_id'] : null,
            $data['reference'] ?? null,
            $data['notes'] ?? null,
        );

        return (new PaymentReportResource($report->load(['moneyAccount', 'payment'])))->response()->setStatusCode(201);
    }

    /**
     * Retira un comprobante propio que todavía no se revisó.
     */
    public function destroy(Request $request, int $report): Response
    {
        $report = PaymentReport::query()->where('user_id', $request->user()->id)->find($report);

        abort_if($report === null, 404, 'No encontramos este comprobante.');

        if (! $report->isPending()) {
            throw ValidationException::withMessages(['status' => 'Este comprobante ya fue revisado.']);
        }

        // Soft delete: queda el registro y el archivo, y deja de aparecer.
        $report->delete();

        return response()->noContent();
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeReview($request);
        $request->validate(['status' => ['nullable', Rule::in(['pendiente', 'todos'])]]);

        $reports = PaymentReport::query()
            ->when($request->input('status', 'pendiente') === 'pendiente', fn ($query) => $query->pending()->oldest())
            ->when($request->input('status') === 'todos', fn ($query) => $query->latest()->limit(50))
            ->with(['family.students', 'user', 'moneyAccount', 'payment'])
            ->orderBy('id')
            ->get();

        return response()->json(['data' => ReviewPaymentReportResource::collection($reports)->toArray($request)]);
    }

    public function approve(Request $request, int $report, ReviewPaymentReport $review): ReviewPaymentReportResource
    {
        $this->authorizeReview($request);
        $data = $request->validate([
            'money_account_id' => ['nullable', 'integer'],
            'received_on' => ['nullable', 'date', 'before_or_equal:'.$this->current->get()->today()->toDateString()],
            'amount' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ], [
            'received_on.before_or_equal' => 'La fecha no puede ser futura.',
        ]);

        $account = null;
        if (isset($data['money_account_id'])) {
            $account = PaymentReportAccess::paymentAccounts()->firstWhere('id', (int) $data['money_account_id']);
            if ($account === null) {
                throw ValidationException::withMessages(['money_account_id' => 'Elegí una cuenta activa.']);
            }
        }

        $report = $review->approve(
            $this->find($report),
            $request->user(),
            $account,
            isset($data['received_on']) ? CarbonImmutable::parse($data['received_on']) : null,
            isset($data['amount']) ? (int) $data['amount'] : null,
        );

        // Si la registró alguien del club, a quién le llega el recibo (a quien no, WhatsApp con el link).
        return (new ReviewPaymentReportResource($report->load(['family.students', 'user', 'moneyAccount', 'payment'])))
            ->additional(['notice' => $report->registered_by_staff ? ReceiptNotice::toArray($report->payment) : null]);
    }

    public function reject(Request $request, int $report, ReviewPaymentReport $review): ReviewPaymentReportResource
    {
        $this->authorizeReview($request);
        $report = $this->find($report);
        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Contale '.Terms::toPerson('guardian', 'Tutor', $report->reporterGender()).' por qué no lo aprobás.'],
        );

        $report = $review->reject($report, $request->user(), $data['reason']);

        return new ReviewPaymentReportResource($report->load(['family.students', 'user', 'moneyAccount', 'payment']));
    }

    private function find(int $report): PaymentReport
    {
        $report = PaymentReport::query()->find($report);

        abort_if($report === null, 404, 'No encontramos este comprobante.');

        return $report;
    }

    private function authorizeReview(Request $request): void
    {
        abort_unless(
            PaymentReportAccess::canReview($request->user(), $this->current->get()),
            403,
            'No tenés permiso para validar comprobantes.',
        );
    }
}
