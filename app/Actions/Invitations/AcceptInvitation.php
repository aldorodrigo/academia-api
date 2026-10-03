<?php

namespace App\Actions\Invitations;

use App\Enums\MembershipStatus;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Roles\RoleAssigner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Acepta una invitación: crea la cuenta si hace falta, activa la membresía,
 * asigna los roles y devuelve un token Sanctum para la app.
 */
class AcceptInvitation
{
    public function __construct(private RoleAssigner $assigner) {}

    /**
     * @param  array{name?: ?string, password: string, device_name: string}  $data
     * @return array{0: User, 1: string}
     */
    public function handle(Invitation $invitation, array $data): array
    {
        return DB::transaction(function () use ($invitation, $data) {
            $invitation = Invitation::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invitation->id);

            abort_unless($invitation->canBeAccepted(), 404, 'La invitación no es válida o ya venció.');

            $user = $invitation->existingUser();

            if ($user === null) {
                // Una cuenta sin verificar con ese celular o correo se reemplaza.
                User::query()->pending()
                    ->where(fn ($query) => filled($invitation->phone)
                        ? $query->where('phone', $invitation->phone)
                        : $query->where('email', $invitation->email))
                    ->get()
                    ->each->delete();

                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $invitation->email,
                    'phone' => $invitation->phone,
                    'password' => $data['password'],
                ]);
                // El link llegó a ese correo o a ese WhatsApp: queda verificado.
                $user->forceFill(filled($invitation->phone)
                    ? ['phone_verified_at' => now()]
                    : ['email_verified_at' => now()])->save();
            } elseif (! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'La contraseña no es correcta.']);
            }

            $organization = $invitation->organization;

            $organization->memberships()->updateOrCreate(
                ['user_id' => $user->id],
                ['status' => MembershipStatus::Active],
            );

            foreach ($invitation->roles as $item) {
                $role = Role::query()->where('organization_id', $organization->id)->where('name', $item['role'])->first();

                if ($role === null || $this->alreadyHas($user, $role)) {
                    continue;
                }

                $this->assigner->assign(
                    $organization,
                    $user,
                    $role,
                    $item['starts_on'] ? Carbon::parse($item['starts_on']) : null,
                    $item['ends_on'] ? Carbon::parse($item['ends_on']) : null,
                    $invitation->invitedBy,
                );
            }

            $this->linkGuardian($invitation, $user);
            $this->assignGroups($invitation, $user);

            $invitation->update(['accepted_at' => now(), 'accepted_user_id' => $user->id]);

            return [$user, $user->createToken($data['device_name'])->plainTextToken];
        });
    }

    /**
     * Invitación enviada a un tutor cargado: el tutor queda vinculado a la cuenta
     * (si no lo estaba ya a otra) y la app le muestra sus hijos.
     */
    private function linkGuardian(Invitation $invitation, User $user): void
    {
        $guardian = $invitation->guardian_id === null ? null
            : Guardian::query()->withoutGlobalScopes()->find($invitation->guardian_id);

        if ($guardian === null || ($guardian->user_id !== null && $guardian->user_id !== $user->id)) {
            return;
        }

        // Un usuario es un solo tutor por organización.
        $alreadyLinked = Guardian::query()->withoutGlobalScopes()
            ->where('organization_id', $guardian->organization_id)
            ->where('user_id', $user->id)
            ->whereKeyNot($guardian->id)
            ->exists();

        if (! $alreadyLinked) {
            $guardian->update(['user_id' => $user->id]);
        }
    }

    /**
     * Técnico invitado con sus categorías: queda asignado a las que siguen existiendo.
     */
    private function assignGroups(Invitation $invitation, User $user): void
    {
        if (empty($invitation->group_ids)) {
            return;
        }

        $groups = Group::query()->withoutGlobalScopes()
            ->where('organization_id', $invitation->organization_id)
            ->whereKey($invitation->group_ids)
            ->pluck('id');

        $user->instructedGroups()->syncWithoutDetaching($groups->all());
    }

    private function alreadyHas(User $user, Role $role): bool
    {
        return RoleAssignment::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->whereNull('ended_at')
            ->exists();
    }
}
