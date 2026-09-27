<?php

namespace App\Actions\Billing;

use App\Jobs\IssueEnrollmentCharges;
use App\Models\Charge;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

/**
 * Encola las cuotas de muchas inscripciones (pase de temporada, importación) y avisa
 * al usuario cuando terminan ("Se crearon N cuotas").
 */
class QueueEnrollmentCharges
{
    /**
     * @param  list<int>  $enrollmentIds
     */
    public function handle(array $enrollmentIds, ?User $notify = null): void
    {
        if ($enrollmentIds === []) {
            return;
        }

        $userId = $notify?->id;
        $startedAt = now()->subSecond();

        Bus::batch(array_map(fn (int $id) => new IssueEnrollmentCharges($id, $userId), $enrollmentIds))
            ->name('Cuotas de '.count($enrollmentIds).' inscripciones')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($enrollmentIds, $userId, $startedAt) {
                $user = $userId !== null ? User::query()->find($userId) : null;

                if ($user === null) {
                    return;
                }

                $created = Charge::query()->withoutGlobalScopes()
                    ->whereIn('enrollment_id', $enrollmentIds)
                    ->whereNotNull('period_start')
                    ->where('created_at', '>=', $startedAt)
                    ->count();

                Notification::make()
                    ->title($batch->failedJobs > 0 ? 'Cuotas creadas con errores' : 'Cuotas creadas')
                    ->body("Se crearon {$created} cuotas para ".count($enrollmentIds).' inscripciones.'
                        .($batch->failedJobs > 0 ? " Fallaron {$batch->failedJobs}: volvé a generarlas desde Cuotas." : ''))
                    ->status($batch->failedJobs > 0 ? 'warning' : 'success')
                    ->sendToDatabase($user);
            })
            ->dispatch();
    }
}
