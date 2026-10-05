<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\CashCollectionAccess;
use App\Actions\Treasury\CashDeposits;
use App\Filament\Support\Terms;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ReceiptController;
use App\Http\Resources\Api\V1\CashDepositResource;
use App\Models\CashDeposit;
use App\Models\LedgerEntry;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Vocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * "Mi caja": la plata del club que tiene quien cobra en efectivo, sus movimientos y los
 * depósitos que informa (quedan por confirmar).
 */
class CashBoxController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->authorizeCollect($request);
        $box = MoneyAccount::cashBoxOf($user, $this->current->get());
        $accounts = MoneyAccount::query()->club()->where('is_active', true)->orderBy('id')->get()
            ->map(fn (MoneyAccount $account) => ['id' => $account->id, 'name' => $account->name, 'type' => $account->type->value])
            ->values();

        if ($box === null) {
            return response()->json(['data' => [
                'id' => null, 'name' => null, 'active' => true,
                'balance' => 0, 'pending_deposits' => 0, 'available' => 0,
                'movements' => [], 'deposits' => [], 'deposit_accounts' => $accounts,
                'collects_to_org_cash' => CashCollectionAccess::collectsToOrgCash($user, $this->current->get()),
            ]]);
        }

        $balance = $box->balance();
        $pending = CashDeposits::pendingAmount($box);
        $entries = $box->entries()->with('source')->orderByDesc('occurred_on')->orderByDesc('id')->limit(50)->get();
        $deposits = CashDeposit::query()->where('money_account_id', $box->id)
            ->with(['toAccount', 'user', 'transfer'])->latest()->orderByDesc('id')->limit(20)->get();

        return response()->json(['data' => [
            'id' => $box->id,
            'name' => $box->name,
            'active' => $box->is_active,
            'balance' => $balance,
            'pending_deposits' => $pending,
            'available' => max(0, $balance - $pending),
            'movements' => $entries->map(fn (LedgerEntry $entry) => $this->movement($entry))->values(),
            'deposits' => CashDepositResource::collection($deposits)->toArray($request),
            'deposit_accounts' => $accounts,
            'collects_to_org_cash' => CashCollectionAccess::collectsToOrgCash($user, $this->current->get()),
        ]]);
    }

    public function deposit(Request $request, CashDeposits $deposits): JsonResponse
    {
        $user = $this->authorizeCollect($request);
        $today = $this->current->get()->today()->toDateString();
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'money_account_id' => ['required', 'integer'],
            'deposited_on' => ['required', 'date', "before_or_equal:{$today}"],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.required' => 'Ingresá el monto que depositaste.',
            'amount.min' => 'El monto tiene que ser mayor a cero.',
            'money_account_id.required' => 'Elegí dónde lo depositaste.',
            'deposited_on.before_or_equal' => 'La fecha del depósito no puede ser futura.',
        ]);

        $box = MoneyAccount::cashBoxOf($user, $this->current->get());
        if ($box === null) {
            throw ValidationException::withMessages(['amount' => 'No tenés efectivo para depositar.']);
        }
        $to = MoneyAccount::query()->find($data['money_account_id']);
        if ($to === null) {
            throw ValidationException::withMessages(['money_account_id' => 'Elegí una cuenta '.Vocabulary::of(Terms::organization()).'.']);
        }

        $deposit = $deposits->submit(
            $user,
            $box,
            $to,
            (int) $data['amount'],
            CarbonImmutable::parse($data['deposited_on']),
            $data['reference'] ?? null,
            $data['notes'] ?? null,
        );

        return (new CashDepositResource($deposit->load(['toAccount', 'user'])))->response()->setStatusCode(201);
    }

    public function withdraw(Request $request, int $deposit, CashDeposits $deposits): Response
    {
        $user = $this->authorizeCollect($request);
        $deposit = CashDeposit::query()->where('user_id', $user->id)->find($deposit);

        abort_if($deposit === null, 404, 'No encontramos este depósito.');

        $deposits->withdraw($deposit);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function movement(LedgerEntry $entry): array
    {
        $source = $entry->source;
        $kind = match (true) {
            $entry->reverses_id !== null => 'anulacion',
            $source instanceof Payment => 'cobro',
            $source instanceof Transfer && $entry->amount < 0 => 'deposito',
            default => 'otro',
        };

        return [
            'id' => $entry->id,
            'occurred_on' => $entry->occurred_on->toDateString(),
            'description' => $entry->description,
            'amount' => $entry->amount,
            'kind' => $kind,
            'receipt_url' => $kind === 'cobro' ? ReceiptController::signedUrl($source) : null,
        ];
    }

    private function authorizeCollect(Request $request): User
    {
        $user = $request->user();

        abort_unless(CashCollectionAccess::canCollect($user), 403, 'No tenés permiso para cobrar desde la app.');

        return $user;
    }
}
