<?php

namespace App\Actions\Onboarding;

use App\Actions\Invitations\CreateInvitation;
use App\Enums\OrganizationRole;
use App\Models\Group;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Onboarding\Team;
use App\Support\Phone;
use App\Support\Roles\RoleAssigner;
use Illuminate\Validation\ValidationException;

/**
 * Paso "Técnicos" de la guía: invitar con categorías, el admin que también da clases y
 * cambiar las categorías de un técnico.
 */
class ManageInstructors
{
    public function __construct(private CreateInvitation $createInvitation, private RoleAssigner $assigner) {}

    /**
     * Invita a un técnico por celular o por correo (o, si ya lo es, le asigna las categorías sin invitar).
     *
     * @param  list<int>  $groupIds
     * @return array{user: ?User, invitation: ?Invitation, token: ?string}
     */
    public function invite(Organization $organization, User $actor, string $name, ?string $email, array $groupIds, ?string $phone = null): array
    {
        $phone = filled($phone) ? Phone::mobile($phone) : null;
        $email = $phone === null && filled($email) ? mb_strtolower(trim($email)) : null;
        $groupIds = $this->groupIds($organization, $groupIds);

        $isMe = $phone !== null ? $phone === $actor->phone : $email === $actor->email;
        if ($isMe) {
            throw ValidationException::withMessages([$phone !== null ? 'phone' : 'email' => 'Para vos, usá «Yo también doy clases».']);
        }

        $instructor = Team::instructors($organization)->first(
            fn (User $user) => $phone !== null ? $user->phone === $phone : $user->email === $email,
        );

        if ($instructor !== null) {
            $instructor->instructedGroups()->syncWithoutDetaching($groupIds);

            return ['user' => $instructor, 'invitation' => null, 'token' => null];
        }

        [$invitation, $token] = $this->createInvitation->handle(
            $organization,
            $email,
            [['role' => OrganizationRole::Instructor->value]],
            $actor,
            name: $name,
            groupIds: $groupIds,
            phone: $phone,
        );

        return ['user' => null, 'invitation' => $invitation, 'token' => $token];
    }

    /**
     * "Yo también doy clases": rol de técnico vigente y sus categorías (o se lo saca).
     *
     * @param  list<int>  $groupIds
     */
    public function setTeaching(Organization $organization, User $user, bool $teaches, array $groupIds): void
    {
        $current = RoleAssignment::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->current($organization->today()->toDateString())
            ->whereHas('role', fn ($role) => $role->where('name', OrganizationRole::Instructor->value))
            ->get();

        if ($teaches && $current->isEmpty()) {
            $this->assigner->assign($organization, $user, OrganizationRole::Instructor, assignedBy: $user);
        }

        if (! $teaches) {
            $current->each(fn (RoleAssignment $assignment) => $this->assigner->end($assignment));
        }

        $this->syncGroups($organization, $user, $teaches ? $groupIds : []);
    }

    /**
     * @param  list<int>  $groupIds
     */
    public function setGroups(Organization $organization, User $instructor, array $groupIds): void
    {
        $isInstructor = Team::instructors($organization)->contains('id', $instructor->id);

        abort_unless($isInstructor, 404);

        $this->syncGroups($organization, $instructor, $groupIds);
    }

    /**
     * Reemplaza las categorías del usuario en esta organización (las de otras no se tocan).
     *
     * @param  list<int>  $groupIds
     */
    private function syncGroups(Organization $organization, User $user, array $groupIds): void
    {
        $mine = Group::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->pluck('id');

        $user->instructedGroups()->detach($mine->diff($this->groupIds($organization, $groupIds))->all());
        $user->instructedGroups()->syncWithoutDetaching($this->groupIds($organization, $groupIds));
    }

    /**
     * Solo categorías de la organización.
     *
     * @param  list<int>  $groupIds
     * @return list<int>
     */
    private function groupIds(Organization $organization, array $groupIds): array
    {
        return Group::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereKey($groupIds)
            ->pluck('id')
            ->all();
    }
}
