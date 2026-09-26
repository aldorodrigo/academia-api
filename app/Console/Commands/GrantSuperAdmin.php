<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:super-admin {email} {--revoke : Quita el acceso de super admin}')]
#[Description('Otorga o quita el acceso de super admin de la plataforma')]
class GrantSuperAdmin extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No existe un usuario con ese correo.');

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_super_admin' => $grant])->save();

        activity('roles')->performedOn($user)
            ->log($grant ? 'Super admin otorgado' : 'Super admin quitado');

        $this->info($grant ? "{$user->email} ahora es super admin." : "{$user->email} ya no es super admin.");

        return self::SUCCESS;
    }
}
