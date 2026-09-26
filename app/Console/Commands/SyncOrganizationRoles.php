<?php

namespace App\Console\Commands;

use App\Actions\Organizations\EnsureOrganizationRoles;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('organizations:sync-roles')]
#[Description('Crea los roles base que falten en cada organización')]
class SyncOrganizationRoles extends Command
{
    public function handle(EnsureOrganizationRoles $ensureRoles): int
    {
        Organization::query()->each(function (Organization $organization) use ($ensureRoles) {
            $ensureRoles->handle($organization);
            $this->line("Roles al día: {$organization->name}");
        });

        return self::SUCCESS;
    }
}
