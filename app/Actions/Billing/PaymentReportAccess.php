<?php

namespace App\Actions\Billing;

use App\Enums\MembershipStatus;
use App\Enums\MoneyAccountType;
use App\Enums\OrganizationRole;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Vocabulary;
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
     * Quiénes pueden confirmar un depósito o aprobar una transferencia que registró otro: los que validan
     * comprobantes, sin quien la registró (`confirmers` de la app).
     *
     * @return list<array{id: int, name: string}>
     */
    public static function confirmers(Organization $organization, ?User $except = null): array
    {
        return self::reviewers($organization)
            ->reject(fn (User $user) => $except !== null && $user->is($except))
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values()->all();
    }

    /**
     * "…hasta que Óscar Giménez la apruebe", "…hasta que Óscar Giménez o Ana Duarte la aprueben" o, con más (o
     * nadie), "…hasta que alguien de la academia la apruebe". `$verb` en singular y plural: ['la apruebe', 'la aprueben'].
     *
     * @param  list<array{name: string}>  $confirmers
     * @param  array{0: string, 1: string}  $verb
     */
    public static function untilConfirmed(array $confirmers, Organization $organization, array $verb): string
    {
        $names = array_column($confirmers, 'name');

        return match (count($names)) {
            1 => "hasta que {$names[0]} {$verb[0]}",
            2 => "hasta que {$names[0]} o {$names[1]} {$verb[1]}",
            default => 'hasta que alguien '.Vocabulary::of($organization->typeNoun())." {$verb[0]}",
        };
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
