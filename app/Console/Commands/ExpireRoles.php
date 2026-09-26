<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('roles:expire')]
#[Description('Termina los mandatos vencidos y activa los que empiezan hoy')]
class ExpireRoles extends Command
{
    public function handle(CurrentOrganization $current, RoleAssigner $assigner): int
    {
        Organization::query()->each(function (Organization $organization) use ($current, $assigner) {
            $current->run($organization, function (Organization $organization) use ($assigner) {
                $today = $organization->today()->toDateString();

                $expired = RoleAssignment::query()
                    ->whereNull('ended_at')
                    ->where('ends_on', '<', $today)
                    ->get();

                $expired->each(fn (RoleAssignment $assignment) => $assigner->end($assignment));

                RoleAssignment::query()
                    ->whereNull('ended_at')
                    ->where('starts_on', $today)
                    ->get()
                    ->each(fn (RoleAssignment $assignment) => $assigner->sync($organization, $assignment->user, $assignment->role));

                if ($expired->isNotEmpty()) {
                    $this->line("{$organization->name}: {$expired->count()} mandato(s) vencido(s)");
                }
            });
        });

        return self::SUCCESS;
    }
}
