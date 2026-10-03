<?php

namespace App\Support\Onboarding;

use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Técnicos de la organización: con el rol vigente o con una invitación sin aceptar.
 */
class Team
{
    /**
     * Usuarios con el rol de instructor vigente.
     *
     * @return Collection<int, User>
     */
    public static function instructors(Organization $organization): Collection
    {
        $today = $organization->today()->toDateString();

        return User::query()
            ->whereHas('roleAssignments', fn (Builder $assignments) => $assignments
                ->withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->current($today)
                ->whereHas('role', fn (Builder $role) => $role->where('name', OrganizationRole::Instructor->value)))
            ->with(['instructedGroups' => fn ($groups) => $groups->withoutGlobalScopes()
                ->where('groups.organization_id', $organization->id)
                ->orderBy('name')])
            ->orderBy('name')
            ->get();
    }

    /**
     * Invitaciones de técnico pendientes o vencidas (no aceptadas ni borradas), de gente que
     * todavía no es técnico.
     *
     * @return Collection<int, Invitation>
     */
    public static function invitations(Organization $organization, ?Collection $instructors = null): Collection
    {
        $instructors ??= self::instructors($organization);
        $emails = $instructors->pluck('email')->filter()->values();
        $phones = $instructors->pluck('phone')->filter()->values();

        return Invitation::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Invitation $invitation) => collect($invitation->roles)->contains('role', OrganizationRole::Instructor->value)
                && ! (filled($invitation->email) && $emails->contains($invitation->email))
                && ! (filled($invitation->phone) && $phones->contains($invitation->phone)))
            ->values();
    }
}
