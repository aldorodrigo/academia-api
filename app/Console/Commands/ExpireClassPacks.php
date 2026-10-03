<?php

namespace App\Console\Commands;

use App\Actions\Lessons\ExpireClassPacks as ExpirePacks;
use App\Enums\Feature;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('packs:expire {--organization= : slug de una organización}')]
#[Description('Vence los paquetes de clases particulares y avisa los que vencen pronto (idempotente)')]
class ExpireClassPacks extends Command
{
    public function handle(ExpirePacks $expire): int
    {
        $organizations = Organization::query()->active()
            ->when($this->option('organization'), fn ($query, string $slug) => $query->where('slug', $slug))
            ->get()
            ->filter(fn (Organization $organization) => $organization->hasFeature(Feature::PrivateLessons));

        foreach ($organizations as $organization) {
            try {
                ['expired' => $expired, 'warned' => $warned] = $expire->handle($organization);
            } catch (Throwable $e) {
                $this->error("{$organization->name}: {$e->getMessage()}");

                continue;
            }

            if ($expired + $warned > 0) {
                $this->line("{$organization->name}: {$expired} vencidos, {$warned} avisos");
            }
        }

        return self::SUCCESS;
    }
}
