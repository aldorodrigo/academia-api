<?php

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dar de baja una inscripción (panel, quien puede editar inscripciones), con fecha y motivo.
 *
 * La deuda queda como histórica: las cuotas impagas, también la del período en curso, siguen
 * pendientes hasta que se pagan, se anulan o se condonan (WaiveCharges). Solo se anulan solas las
 * cuotas futuras sin pagos (VoidFutureCharges, desde el modelo). Si el técnico había avisado que
 * dejó de venir, el aviso se cierra.
 */
class WithdrawEnrollment
{
    /**
     * @return array{pending_count: int, pending_amount: int, future_count: int} lo que muestra el modal antes de confirmar
     */
    public function preview(Enrollment $enrollment): array
    {
        $today = $enrollment->organization->today()->toDateString();
        $charges = Charge::query()->notVoided()
            ->where('enrollment_id', $enrollment->id)
            ->with(['allocations.payment', 'organization', 'season'])
            ->get();

        $future = $charges->filter(fn (Charge $c) => $c->period_start !== null && $c->period_start->toDateString() > $today && $c->paidAmount() === 0);
        $pending = $charges->diff($future)->filter(fn (Charge $c) => $c->pendingAmount() > 0);

        return [
            'pending_count' => $pending->count(),
            'pending_amount' => (int) $pending->sum(fn (Charge $c) => $c->pendingAmount()),
            'future_count' => $future->count(),
        ];
    }

    public function handle(Enrollment $enrollment, CarbonInterface|string $on, string $reason, ?User $by): Enrollment
    {
        if ($enrollment->isWithdrawn()) {
            throw ValidationException::withMessages(['ended_on' => 'La inscripción ya está dada de baja.']);
        }

        if ($enrollment->isFinished()) {
            throw ValidationException::withMessages(['ended_on' => 'La temporada ya terminó: la inscripción quedó finalizada.']);
        }

        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['withdrawal_reason' => 'Indicá el motivo de la baja.']);
        }

        $on = CarbonImmutable::parse($on)->toDateString();
        $today = $enrollment->organization->today()->toDateString();

        if ($on > $today) {
            throw ValidationException::withMessages(['ended_on' => 'La fecha de baja no puede ser posterior a hoy.']);
        }

        if ($enrollment->enrolled_on !== null && $on < $enrollment->enrolled_on->toDateString()) {
            throw ValidationException::withMessages(['ended_on' => 'La fecha de baja no puede ser anterior a la inscripción ('.$enrollment->enrolled_on->format('d/m/Y').').']);
        }

        return DB::transaction(function () use ($enrollment, $on, $reason, $by) {
            $previous = $enrollment->status;

            $enrollment->update([
                'status' => EnrollmentStatus::Withdrawn,
                'ended_on' => $on,
                'withdrawal_reason' => trim($reason),
                'withdrawn_by' => $by?->id,
            ]);

            activity('academic')->performedOn($enrollment)->causedBy($by)
                ->withProperties(['student_id' => $enrollment->student_id, 'from' => $previous->value, 'ended_on' => $on, 'reason' => trim($reason)])
                ->log('Baja de la inscripción');

            return $enrollment;
        });
    }
}
