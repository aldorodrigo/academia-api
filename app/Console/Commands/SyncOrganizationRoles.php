<?php

namespace App\Console\Commands;

use App\Actions\Organizations\EnsureExpenseCategories;
use App\Actions\Organizations\EnsureFeeConcepts;
use App\Actions\Organizations\EnsureMoneyAccounts;
use App\Actions\Organizations\EnsureOrganizationRoles;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('organizations:sync-roles')]
#[Description('Crea los roles base, los conceptos de cobro, la caja y las categorías de gasto que falten en cada organización')]
class SyncOrganizationRoles extends Command
{
    public function handle(EnsureOrganizationRoles $ensureRoles, EnsureFeeConcepts $ensureConcepts, EnsureMoneyAccounts $ensureAccounts, EnsureExpenseCategories $ensureCategories): int
    {
        Organization::query()->each(function (Organization $organization) use ($ensureRoles, $ensureConcepts, $ensureAccounts, $ensureCategories) {
            $ensureRoles->handle($organization);
            $ensureConcepts->handle($organization);
            $ensureAccounts->handle($organization);
            $ensureCategories->handle($organization);
            $this->line("Configuración base al día: {$organization->name}");
        });

        return self::SUCCESS;
    }
}
