<?php

namespace App\Actions\Platform;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Otorga o quita el acceso de super admin de la plataforma.
 */
class SetSuperAdmin
{
    public function handle(User $user, bool $grant, ?User $actor = null): void
    {
        if (! $grant) {
            if ($actor !== null && $actor->is($user)) {
                throw ValidationException::withMessages(['user' => 'No podés quitarte el acceso de super admin a vos mismo.']);
            }

            if ($user->is_super_admin && User::query()->where('is_super_admin', true)->count() <= 1) {
                throw ValidationException::withMessages(['user' => 'Tiene que quedar al menos un super admin.']);
            }
        }

        $user->forceFill(['is_super_admin' => $grant])->save();

        activity('platform')
            ->performedOn($user)
            ->causedBy($actor)
            ->log($grant ? 'Super admin otorgado' : 'Super admin quitado');
    }
}
