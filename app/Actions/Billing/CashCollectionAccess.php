<?php

namespace App\Actions\Billing;

use App\Actions\Attendance\AttendanceAccess;
use App\Enums\MembershipStatus;
use App\Enums\MoneyAccountType;
use App\Models\Membership;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Vocabulary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Quién cobra en efectivo desde la app y a quién: el permiso "Cobrar en efectivo desde la
 * app" (técnico, tesorero y protesorero por defecto; el admin por Gate::before). Quien ve
 * todos los alumnos les cobra a todos; el resto, a los inscriptos en sus grupos.
 */
class CashCollectionAccess
{
    public const PERMISSION = 'Collect:Payments';

    public static function canCollect(User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    /**
     * "Cobra directo a la Caja": lo que cobra en efectivo entra en una cuenta del club (por defecto la
     * Caja), sin caja personal ni depósito. Se elige por persona en su membresía.
     */
    public static function collectsToOrgCash(User $user, Organization $organization): bool
    {
        return (bool) Membership::of($user, $organization)?->collects_to_org_cash;
    }

    /**
     * Cuentas del club donde puede entrar el efectivo de quien cobra directo (activas, sin titular).
     *
     * @return Collection<int, MoneyAccount>
     */
    public static function collectAccounts(): Collection
    {
        return MoneyAccount::query()->club()->where('is_active', true)->orderBy('id')->get();
    }

    /**
     * La Caja del club (la primera cuenta de efectivo sin titular), donde entra por defecto.
     */
    public static function orgCash(): ?MoneyAccount
    {
        $accounts = self::collectAccounts();

        return $accounts->first(fn (MoneyAccount $account) => $account->type === MoneyAccountType::Cash) ?? $accounts->first();
    }

    /**
     * Quienes pueden cobrar en efectivo (miembros activos con el permiso), para elegir quién cobra directo.
     *
     * @return Collection<int, Membership>
     */
    public static function collectors(Organization $organization): Collection
    {
        return $organization->memberships()->where('status', MembershipStatus::Active->value)->with('user')->get()
            ->filter(fn (Membership $membership) => $membership->user !== null && self::canCollect($membership->user))
            ->sortBy(fn (Membership $membership) => mb_strtolower($membership->user->name))
            ->values();
    }

    /**
     * Quiénes pueden reabrir una caja cerrada (editar cuentas: el admin por Gate::before y quien tenga el permiso),
     * miembros activos, sin quien la tiene (`reopeners` de la app).
     *
     * @return list<array{id: int, name: string}>
     */
    public static function reopeners(Organization $organization, ?User $except = null): array
    {
        return app(CurrentOrganization::class)->run($organization, fn () => $organization->users()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->get()
            ->reject(fn (User $user) => $except !== null && $user->is($except))
            ->filter(fn (User $user) => $user->can('Update:MoneyAccount'))
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values()->all());
    }

    /**
     * "Tu caja está cerrada. Hablá con Óscar Giménez para reabrirla." (con dos, "con Óscar Giménez o Ana Duarte"; con
     * más o con nadie, "con quien maneja las cuentas del club").
     *
     * @param  list<array{name: string}>  $reopeners
     */
    public static function closedBoxMessage(array $reopeners, Organization $organization): string
    {
        $names = array_column($reopeners, 'name');
        $who = match (count($names)) {
            1 => $names[0],
            2 => "{$names[0]} o {$names[1]}",
            default => 'quien maneja las cuentas '.Vocabulary::of($organization->typeNoun()),
        };

        return "Tu caja está cerrada. Hablá con {$who} para reabrirla.";
    }

    /**
     * Alumnos a los que puede cobrar (inscripción en una temporada vigente o próxima).
     *
     * @return Builder<Student>
     */
    public static function students(User $user): Builder
    {
        if ($user->can('ViewAny:Student')) {
            return Student::query();
        }

        $groups = AttendanceAccess::groups($user)->select('groups.id');

        return Student::query()->whereHas('currentEnrollments', fn (Builder $query) => $query->whereIn('group_id', $groups));
    }
}
