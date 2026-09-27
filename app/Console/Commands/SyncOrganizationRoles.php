<?php

namespace App\Console\Commands;

use App\Actions\Organizations\EnsureFeeConcepts;
use App\Actions\Organizations\EnsureOrganizationRoles;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('organizations:sync-roles')]
#[Description('Crea los roles base y los conceptos de cobro que falten en cada organización')]
class SyncOrganizationRoles extends Command
{
    public function handle(EnsureOrganizationRoles $ensureRoles, EnsureFeeConcepts $ensureConcepts): int
    {
        Organization::query()->each(function (Organization $organization) use ($ensureRoles, $ensureConcepts) {
            $ensureRoles->handle($organization);
            $ensureConcepts->handle($organization);
            $this->line("Roles y conceptos al día: {$organization->name}");
        });

        return self::SUCCESS;
    }
}
