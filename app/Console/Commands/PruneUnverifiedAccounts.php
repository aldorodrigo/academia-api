<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Borra las cuentas que no ingresaron el código en 24 horas (no ocupan el número ni el correo).
 */
#[Signature('accounts:prune-unverified')]
#[Description('Borra las cuentas sin verificar de más de 24 horas')]
class PruneUnverifiedAccounts extends Command
{
    public function handle(): int
    {
        $count = 0;

        User::query()
            ->pending()
            // El super admin se crea por consola (make:filament-user), sin código.
            ->where('is_super_admin', false)
            ->where('created_at', '<', now()->subDay())
            ->chunkById(100, function ($users) use (&$count): void {
                $users->each->delete();
                $count += $users->count();
            });

        $this->info("Cuentas borradas: {$count}");

        return self::SUCCESS;
    }
}
