<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\CashCollectionAccess;
use App\Actions\Billing\PaymentReportAccess;
use App\Actions\Billing\SetCollectsToOrgCash;
use App\Actions\Treasury\CashDeposits;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CashDepositResource;
use App\Models\CashDeposit;
use App\Models\Membership;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Efectivo" para quien valida comprobantes: cuánto tiene cada caja personal y los depósitos
 * por confirmar (confirmar registra la transferencia; rechazar deja la plata en su caja).
 */
class CashDepositController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeReview($request);
        $organization = $this->current->get();

        $pending = CashDeposit::query()->pending()->with(['toAccount', 'user', 'transfer'])->oldest()->orderBy('id')->get();
        $active = $organization->memberships()->where('status', MembershipStatus::Active->value)->pluck('user_id')->all();

        $boxes = MoneyAccount::query()->cashBoxes()->with('holder')->withSum('entries', 'amount')->withMax('entries', 'occurred_on')->get()
            ->map(fn (MoneyAccount $box) => [
                'id' => $box->id,
                'name' => $box->name,
                'holder' => ['id' => $box->holder->id, 'name' => $box->holder->name, 'active' => in_array($box->user_id, $active, true)],
                'balance' => (int) $box->entries_sum_amount,
                'pending_deposits' => (int) $pending->where('money_account_id', $box->id)->sum('amount'),
                'last_movement_on' => $box->entries_max_occurred_on === null ? null : substr((string) $box->entries_max_occurred_on, 0, 10),
            ])
            ->filter(fn (array $box) => $box['balance'] !== 0 || $box['pending_deposits'] > 0)
            ->sortByDesc('balance')
            ->values();

        return response()->json(['data' => [
            'total' => (int) $boxes->sum('balance'),
            'boxes' => $boxes,
            'deposits' => CashDepositResource::collection($pending)->toArray($request),
            // Quién cobra directo a la Caja: solo para quien administra los miembros.
            ...(self::canManageCollectors($request->user(), $organization) ? [
                'collectors' => CashCollectionAccess::collectors($organization)
                    ->map(fn (Membership $membership) => $this->collector($membership, $organization))->values(),
            ] : []),
        ]]);
    }

    /**
     * "Cobra directo a la Caja" sí/no para una persona que cobra en efectivo.
     */
    public function updateCollector(Request $request, int $user, SetCollectsToOrgCash $set): JsonResponse
    {
        $organization = $this->current->get();
        abort_unless(self::canManageCollectors($request->user(), $organization), 403, 'No podés cambiar cómo cobra cada uno.');
        $data = $request->validate(['collects_to_org_cash' => ['required', 'boolean']]);

        $membership = CashCollectionAccess::collectors($organization)->firstWhere('user_id', $user);
        abort_if($membership === null, 404, 'No encontramos a esta persona entre quienes cobran.');

        $membership = $set->handle($membership, (bool) $data['collects_to_org_cash'], $request->user());

        return response()->json(['data' => $this->collector($membership, $organization)]);
    }

    /**
     * Quien administra los miembros (permiso de editar miembros; el admin siempre).
     */
    public static function canManageCollectors(User $user, Organization $organization): bool
    {
        return $user->can('update', new Membership(['organization_id' => $organization->id]));
    }

    /**
     * @return array{user_id: int, name: string, collects_to_org_cash: bool, owner: bool}
     */
    private function collector(Membership $membership, Organization $organization): array
    {
        return [
            'user_id' => $membership->user_id,
            'name' => $membership->user->name,
            'collects_to_org_cash' => (bool) $membership->collects_to_org_cash,
            'owner' => $organization->owner_id === $membership->user_id,
        ];
    }

    public function confirm(Request $request, int $deposit, CashDeposits $deposits): CashDepositResource
    {
        $this->authorizeReview($request);

        $deposit = $deposits->confirm($this->find($deposit), $request->user());

        return new CashDepositResource($deposit->load(['toAccount', 'user', 'transfer']));
    }

    public function reject(Request $request, int $deposit, CashDeposits $deposits): CashDepositResource
    {
        $this->authorizeReview($request);
        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Contale por qué no lo confirmás.'],
        );

        $deposit = $deposits->reject($this->find($deposit), $request->user(), $data['reason']);

        return new CashDepositResource($deposit->load(['toAccount', 'user', 'transfer']));
    }

    private function find(int $deposit): CashDeposit
    {
        $deposit = CashDeposit::query()->find($deposit);

        abort_if($deposit === null, 404, 'No encontramos este depósito.');

        return $deposit;
    }

    private function authorizeReview(Request $request): void
    {
        abort_unless(
            PaymentReportAccess::canReview($request->user(), $this->current->get()),
            403,
            'No tenés permiso para confirmar depósitos.',
        );
    }
}
