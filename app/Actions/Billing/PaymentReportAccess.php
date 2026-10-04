<?php

namespace App\Actions\Billing;

use App\Enums\MembershipStatus;
use App\Enums\MoneyAccountType;
use App\Enums\OrganizationRole;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Collection;

/**
 * Quién valida los comprobantes de transferencia: el tesorero y el protesorero
 * por su cargo, y cualquiera con el permiso "Validar comprobantes" (el admin lo
 * tiene por Gate::before).
 */
class PaymentReportAccess
{
    public const PERMISSION = 'Review:PaymentReports';

    public static function canReview(User $user, Organization $organization): bool
    {
        return $user->can(self::PERMISSION)
            || $user->hasCurrentRole($organization, OrganizationRole::Treasurer)
            || $user->hasCurrentRole($organization, OrganizationRole::DeputyTreasurer);
    }

    /**
     * Miembros activos que reciben el aviso de un comprobante nuevo.
     *
     * @return Collection<int, User>
     */
    public static function reviewers(Organization $organization): Collection
    {
        return app(CurrentOrganization::class)->run($organization, fn () => $organization->users()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->get()
            ->filter(fn (User $user) => self::canReview($user, $organization))
            ->values());
    }

    /**
     * Cuentas donde puede entrar una transferencia que registra el club: bancos y billeteras activas del club
     * (sin las cajas personales), tengan o no datos para transferir.
     *
     * @return Collection<int, MoneyAccount>
     */
    public static function clubTransferAccounts(): Collection
    {
        return MoneyAccount::query()->club()
            ->where('is_active', true)
            ->whereIn('type', [MoneyAccountType::Bank, MoneyAccountType::Wallet])
            ->orderBy('id')
            ->get();
    }

    /**
     * Cuentas del club donde puede entrar un pago aprobado o registrado en el panel (sin las cajas personales,
     * que solo reciben los cobros en efectivo desde la app).
     *
     * @return Collection<int, MoneyAccount>
     */
    public static function paymentAccounts(): Collection
    {
        return MoneyAccount::query()->club()->where('is_active', true)->orderBy('id')->get();
    }

    /**
     * Cuentas que ve el tutor para transferir: bancos y billeteras activas con datos cargados.
     *
     * @return Collection<int, MoneyAccount>
     */
    public static function transferAccounts(): Collection
    {
        return MoneyAccount::query()
            ->where('is_active', true)
            ->whereIn('type', [MoneyAccountType::Bank, MoneyAccountType::Wallet])
            ->whereNotNull('transfer_details')
            ->where('transfer_details', '!=', '')
            ->orderBy('id')
            ->get();
    }
}
