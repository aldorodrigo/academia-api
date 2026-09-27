<?php

namespace App\Jobs;

use App\Actions\Billing\IssueSeasonCharges;
use App\Models\Enrollment;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cuotas de una inscripción creada en el pase de temporada o la importación (en lote).
 */
class IssueEnrollmentCharges implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(public int $enrollmentId, public ?int $createdBy = null) {}

    public function handle(IssueSeasonCharges $issue): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $enrollment = Enrollment::query()->withoutGlobalScopes()->with(['season', 'group', 'student', 'organization'])->find($this->enrollmentId);

        if ($enrollment !== null) {
            $issue->forEnrollment($enrollment, createdBy: $this->createdBy);
        }
    }
}
