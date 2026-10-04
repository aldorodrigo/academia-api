<?php

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Notifications\StudentWithdrawn;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dar de baja una inscripción (panel, quien puede editar inscripciones), con fecha y motivo.
 *
 * La deuda queda como histórica: las cuotas impagas, también la del período en curso, siguen
 * pendientes hasta que se pagan, se anulan o se condonan (WaiveCharges). Solo se anulan solas las
 * cuotas futuras sin pagos (VoidFutureCharges, desde el modelo). Si el técnico había avisado que
 * dejó de venir, el aviso se cierra.
 *
 * Quien da la baja elige si le avisa a la familia (push y correo a los tutores con la app): el
 * mensaje viene prellenado, amable y con las puertas abiertas, y se puede cambiar.
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

    /**
     * Mensaje sugerido para la familia.
     */
    public static function defaultNotice(Enrollment $enrollment): string
    {
        $enrollment->loadMissing(['student', 'organization']);

        return "Hola, te contamos que registramos la baja de {$enrollment->student->first_name} en {$enrollment->organization->name}. "
            .'¡Gracias por todo este tiempo compartido! Las puertas siempre van a estar abiertas: '
            .'cuando quieran volver, escribinos y los esperamos con mucho gusto.';
    }

    /**
     * Quienes reciben el aviso: los tutores con la app y el alumno adulto, si tiene cuenta.
     *
     * @return Collection<int, User>
     */
    public static function noticeRecipients(Student $student): Collection
    {
        $student->loadMissing(['guardians.user', 'user']);

        return $student->guardians
            ->map(fn (Guardian $guardian) => $guardian->user)
            ->push($student->user)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Avisa a la familia (mensaje ya revisado por quien da la baja). Devuelve a cuántos les llegó.
     */
    public function notify(Enrollment $enrollment, string $message, ?User $by): int
    {
        if (blank(trim($message))) {
            throw ValidationException::withMessages(['message' => 'Escribí el mensaje para la familia.']);
        }

        $recipients = self::noticeRecipients($enrollment->student);
        $notification = new StudentWithdrawn("Baja de {$enrollment->student->first_name}", trim($message));
        $recipients->each(fn (User $user) => $user->notify($notification));

        activity('academic')->performedOn($enrollment)->causedBy($by)
            ->withProperties(['student_id' => $enrollment->student_id, 'recipients' => $recipients->count(), 'message' => trim($message)])
            ->log('Aviso de baja a la familia');

        return $recipients->count();
    }

    public function handle(Enrollment $enrollment, CarbonInterface|string $on, string $reason, ?User $by, ?string $notice = null): Enrollment
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

        if ($notice !== null && blank(trim($notice))) {
            throw ValidationException::withMessages(['message' => 'Escribí el mensaje para la familia.']);
        }

        $on = CarbonImmutable::parse($on)->toDateString();
        $today = $enrollment->organization->today()->toDateString();

        if ($on > $today) {
            throw ValidationException::withMessages(['ended_on' => 'La fecha de baja no puede ser posterior a hoy.']);
        }

        if ($enrollment->enrolled_on !== null && $on < $enrollment->enrolled_on->toDateString()) {
            throw ValidationException::withMessages(['ended_on' => 'La fecha de baja no puede ser anterior a la inscripción ('.$enrollment->enrolled_on->format('d/m/Y').').']);
        }

        return DB::transaction(function () use ($enrollment, $on, $reason, $by, $notice) {
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

            if ($notice !== null) {
                $this->notify($enrollment, $notice, $by);
            }

            return $enrollment;
        });
    }
}
