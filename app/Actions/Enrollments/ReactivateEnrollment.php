<?php

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El alumno vuelve: la inscripción dada de baja pasa a activa (o becada). La deuda que dejó
 * sigue en su cuenta para pagarse; las cuotas se emiten desde el período en curso (los meses que
 * estuvo afuera no se cobran) y se reemiten las futuras que se anularon con la baja (desde el modelo).
 */
class ReactivateEnrollment
{
    /**
     * @return int lo que sigue pendiente del alumno (para avisarle a quien reactiva)
     */
    public function handle(Enrollment $enrollment, EnrollmentStatus $status, ?User $by): int
    {
        if (! $enrollment->isWithdrawn()) {
            throw ValidationException::withMessages(['status' => 'La inscripción no está dada de baja.']);
        }

        if ($enrollment->season->hasEnded()) {
            throw ValidationException::withMessages(['status' => 'La temporada ya terminó: inscribilo en una vigente.']);
        }

        if (! in_array($status, [EnrollmentStatus::Active, EnrollmentStatus::Scholarship], true)) {
            throw ValidationException::withMessages(['status' => 'Elegí activo o becado.']);
        }

        return DB::transaction(function () use ($enrollment, $status, $by) {
            $endedOn = $enrollment->ended_on?->toDateString();

            $enrollment->update(['status' => $status]);

            activity('academic')->performedOn($enrollment)->causedBy($by)
                ->withProperties(['student_id' => $enrollment->student_id, 'to' => $status->value, 'ended_on' => $endedOn])
                ->log('Inscripción reactivada');

            return (int) Charge::query()->notVoided()
                ->where('student_id', $enrollment->student_id)
                ->with(['allocations.payment', 'organization', 'season'])
                ->get()
                ->reject(fn (Charge $c) => $c->isUpcoming())
                ->sum(fn (Charge $c) => $c->pendingAmount());
        });
    }
}
