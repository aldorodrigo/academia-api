<?php

namespace App\Console\Commands;

use App\Actions\Attendance\SendClassReminders as SendReminders;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('classes:remind {--organization= : slug de una organización}')]
#[Description('Manda el aviso "¿Lo llevás?" de las clases que se acercan a quienes lo pidieron (idempotente)')]
class SendClassReminders extends Command
{
    public function handle(SendReminders $send): int
    {
        $organizations = Organization::query()->active()
            ->when($this->option('organization'), fn ($query, string $slug) => $query->where('slug', $slug))
            ->get();

        foreach ($organizations as $organization) {
            try {
                $sent = $send->handle($organization);
            } catch (Throwable $e) {
                $this->error("{$organization->name}: {$e->getMessage()}");

                continue;
            }

            if ($sent > 0) {
                $this->line("{$organization->name}: {$sent} avisos");
            }
        }

        return self::SUCCESS;
    }
}
