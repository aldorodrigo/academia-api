<?php

namespace App\Actions\Invitations;

use App\Enums\MembershipStatus;
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

            $user = User::query()->where('email', $invitation->email)->first();

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $invitation->email,
                    'password' => $data['password'],
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
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

    private function alreadyHas(User $user, Role $role): bool
    {
        return RoleAssignment::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->whereNull('ended_at')
            ->exists();
    }
}
