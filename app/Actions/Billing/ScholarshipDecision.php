<?php

namespace App\Actions\Billing;

use App\Enums\EnrollmentStatus;
use App\Enums\ScholarshipStatus;
use App\Models\Enrollment;
use App\Models\Scholarship;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Becas: se cargan pendientes y las aprueba, rechaza o revoca quien tiene el
 * permiso Approve:Scholarship. No tocan los cargos ya emitidos.
 */
class ScholarshipDecision
{
    public function request(Enrollment $enrollment, int $percent, string $reason, CarbonInterface $from, ?CarbonInterface $to, User $by): Scholarship
    {
        if ($percent < 1 || $percent > 100) {
            throw ValidationException::withMessages(['percent' => 'El porcentaje va de 1 a 100.']);
        }

        return $enrollment->scholarships()->create([
            'organization_id' => $enrollment->organization_id,
            'student_id' => $enrollment->student_id,
            'percent' => $percent,
            'reason' => $reason,
            'valid_from' => $from->toDateString(),
            'valid_to' => $to?->toDateString(),
            'requested_by' => $by->id,
        ]);
    }

    public function approve(Scholarship $scholarship, User $by, ?string $note = null): Scholarship
    {
        return $this->decide($scholarship, $by, ScholarshipStatus::Approved, $note, EnrollmentStatus::Scholarship);
    }

    public function reject(Scholarship $scholarship, User $by, ?string $note = null): Scholarship
    {
        return $this->decide($scholarship, $by, ScholarshipStatus::Rejected, $note);
    }

    /**
     * Deja de aplicarse desde ahora; la inscripción vuelve a activo si no tiene otra beca.
     */
    public function revoke(Scholarship $scholarship, User $by, ?string $note = null): Scholarship
    {
        if ($scholarship->status !== ScholarshipStatus::Approved) {
            throw ValidationException::withMessages(['status' => 'Solo se revoca una beca aprobada.']);
        }

        Gate::forUser($by)->authorize('approve', $scholarship);

        return DB::transaction(function () use ($scholarship, $by, $note) {
            $scholarship->update([
                'status' => ScholarshipStatus::Revoked,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            $enrollment = $scholarship->enrollment;
            $stillScholarship = $enrollment->scholarships()->where('status', ScholarshipStatus::Approved)->exists();

            if (! $stillScholarship && $enrollment->status === EnrollmentStatus::Scholarship) {
                $enrollment->update(['status' => EnrollmentStatus::Active]);
            }

            return $scholarship;
        });
    }

    private function decide(Scholarship $scholarship, User $by, ScholarshipStatus $status, ?string $note, ?EnrollmentStatus $enrollmentStatus = null): Scholarship
    {
        if ($scholarship->status !== ScholarshipStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'La beca ya fue resuelta.']);
        }

        Gate::forUser($by)->authorize('approve', $scholarship);

        return DB::transaction(function () use ($scholarship, $by, $status, $note, $enrollmentStatus) {
            $scholarship->update([
                'status' => $status,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            if ($enrollmentStatus !== null) {
                $scholarship->enrollment->update(['status' => $enrollmentStatus]);
            }

            return $scholarship;
        });
    }
}
