<?php

namespace App\Actions\Attendance;

use App\Actions\Billing\WaiveSuspendedClass;
use App\Enums\ClassStatus;
use App\Models\ClassSession;
use App\Models\User;
use App\Notifications\ClassRescheduled;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Pasa una clase a otro día u horario: crea la recuperación (una clase más del
 * grupo, fuera del horario semanal) y deja la original "reprogramada". Avisa a
 * los tutores del grupo y a los otros técnicos. No cambia las cuotas: si la
 * original estaba suspendida sin cobrar, vuelve a cobrarse (se recupera).
 */
class RescheduleClass
{
    public function __construct(
        private ResolveClassSessions $sessions,
        private WaiveSuspendedClass $waiver,
    ) {}

    /**
     * @param  array{date: string, starts_at: string, ends_at: string, venue_id?: ?int, reason?: ?string}  $data
     */
    public function handle(ClassSession $session, array $data, ?User $user): ClassSession
    {
        $organization = $session->organization;
        $now = CarbonImmutable::now($organization->timezone);
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $data['date'], $organization->timezone);
        $startsAt = substr($data['starts_at'], 0, 5);
        $endsAt = substr($data['ends_at'], 0, 5);

        if ($session->isRescheduled()) {
            throw ValidationException::withMessages(['date' => 'La clase ya está reprogramada.']);
        }

        if ($session->isPast($now->startOfDay()) || $session->hasStarted($now)) {
            throw ValidationException::withMessages(['date' => 'Una clase que ya empezó no se puede reprogramar.']);
        }

        if ($endsAt <= $startsAt) {
            throw ValidationException::withMessages(['ends_at' => 'La hora de fin tiene que ser después del inicio.']);
        }

        if (! $now->lt(CarbonImmutable::parse("{$data['date']} {$startsAt}", $organization->timezone))) {
            throw ValidationException::withMessages(['date' => 'Elegí un día y hora que todavía no pasó.']);
        }

        // Que no se superponga con otra clase del grupo ese día (incluidas las del horario).
        $overlaps = $this->sessions->forDate([$session->group], $date)
            ->reject(fn (ClassSession $other) => $other->is($session) || $other->isOff())
            ->contains(fn (ClassSession $other) => substr($other->starts_at, 0, 5) < $endsAt
                && substr($other->ends_at, 0, 5) > $startsAt);

        if ($overlaps) {
            throw ValidationException::withMessages(['starts_at' => 'Ese horario se superpone con otra clase del grupo.']);
        }

        $makeup = DB::transaction(function () use ($session, $data, $date, $startsAt, $endsAt, $user) {
            $waived = $session->charge_waived;

            $makeup = ClassSession::query()->create([
                'organization_id' => $session->organization_id,
                'group_id' => $session->group_id,
                'date' => $date->toDateString(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'venue_id' => array_key_exists('venue_id', $data) && $data['venue_id'] !== null ? $data['venue_id'] : $session->venue_id,
                'is_makeup' => true,
            ]);

            $session->update([
                'status' => ClassStatus::Rescheduled,
                'suspension_reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : null,
                'suspended_by' => $user?->id,
                'charge_waived' => false,
                'rescheduled_to_id' => $makeup->id,
            ]);

            // Reprogramar no descuenta: si estaba suspendida sin cobrar, las cuotas vuelven a contar el día.
            if ($waived) {
                $this->waiver->undo($session, $user);
            }

            return $makeup;
        });

        $this->notify($session->refresh(), $makeup, $user, cancelled: false);

        return $makeup;
    }

    /**
     * Cancela la reprogramación (antes de que empiece la recuperación y sin asistencia):
     * borra la recuperación y la original vuelve a suspendida (si tenía motivo) o programada.
     */
    public function cancel(ClassSession $session, ?User $user): void
    {
        $makeup = $session->rescheduledTo;

        if (! $session->isRescheduled() || $makeup === null) {
            throw ValidationException::withMessages(['date' => 'La clase no está reprogramada.']);
        }

        $now = CarbonImmutable::now($session->organization->timezone);

        if ($makeup->hasStarted($now) || $makeup->isAttendanceTaken()) {
            throw ValidationException::withMessages(['date' => 'La recuperación ya empezó: no se puede cancelar.']);
        }

        $original = clone $makeup;

        DB::transaction(function () use ($session, $makeup) {
            $session->update([
                'status' => filled($session->suspension_reason) && ! $session->isPast() ? ClassStatus::Suspended : ClassStatus::Scheduled,
                'rescheduled_to_id' => null,
            ]);
            $makeup->delete();
        });

        $this->notify($session->refresh(), $original, $user, cancelled: true);
    }

    private function notify(ClassSession $session, ClassSession $makeup, ?User $user, bool $cancelled): void
    {
        $session->loadMissing(['group.program', 'group.instructors', 'organization']);
        $makeup->loadMissing('venue');
        $students = $session->students();

        $guardians = SuspendClass::usersInChargeOf($students)->reject(fn (User $recipient) => $recipient->is($user));
        $instructors = $session->group->instructors->reject(fn (User $recipient) => $recipient->is($user));

        Notification::send($guardians, new ClassRescheduled($session, $makeup, cancelled: $cancelled, forInstructor: false));
        Notification::send($instructors, new ClassRescheduled($session, $makeup, cancelled: $cancelled, forInstructor: true));
    }
}
