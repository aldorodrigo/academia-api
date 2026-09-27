<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\ClassReminderPreference;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\Organization;
use App\Notifications\ClassReminder;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * "Hoy Mateo tiene Fútbol a las 17:00. ¿Lo llevás?": un push por clase y alumno a quienes
 * pidieron el aviso, unas horas antes (organizations.class_reminder_hours). Si esa hora cae
 * de noche (22:00 a 7:00), sale a las 20:00 del día anterior. No se manda si ya respondieron
 * o si la clase está suspendida. Idempotente por `attendances.reminded_at`.
 */
class SendClassReminders
{
    public const QUIET_FROM = 22;

    public const QUIET_UNTIL = 7;

    public const EVENING = 20;

    public function __construct(
        private CurrentOrganization $current,
        private ResolveClassSessions $sessions,
    ) {}

    /**
     * @return int avisos enviados (alumnos)
     */
    public function handle(Organization $organization, ?CarbonImmutable $now = null): int
    {
        return $this->current->run($organization, function (Organization $organization) use ($now) {
            $now = ($now ?? CarbonImmutable::now())->setTimezone($organization->timezone);
            $today = $now->startOfDay();

            // Solo hace falta mirar grupos con alumnos que pidieron el aviso.
            $students = ClassReminderPreference::query()->where('enabled', true)->pluck('student_id')->unique();

            if ($students->isEmpty()) {
                return 0;
            }

            $groups = Group::query()
                ->whereHas('enrollments', fn ($query) => $query->whereIn('student_id', $students))
                ->with('schedules')
                ->get();

            $sent = 0;

            foreach ($this->sessions->between($groups, $today, $today->addDay()) as $session) {
                if ($session->isSuspended() || $session->hasStarted($now) || $now->lt(self::sendAt($session, $organization->class_reminder_hours))) {
                    continue;
                }

                $sent += $this->remind($session, $students->all(), $today);
            }

            return $sent;
        });
    }

    /**
     * Cuándo sale el aviso de una clase.
     */
    public static function sendAt(ClassSession $session, int $hours): CarbonImmutable
    {
        $at = $session->startsAt()->subHours($hours);

        return match (true) {
            $at->hour < self::QUIET_UNTIL => $at->subDay()->setTime(self::EVENING, 0),
            $at->hour >= self::QUIET_FROM => $at->setTime(self::EVENING, 0),
            default => $at,
        };
    }

    /**
     * @param  list<int>  $optedIn
     */
    private function remind(ClassSession $session, array $optedIn, CarbonImmutable $today): int
    {
        $sent = 0;

        foreach ($session->students()->whereIn('id', $optedIn) as $student) {
            $attendance = Attendance::query()->firstOrCreate(
                ['class_session_id' => $session->id, 'student_id' => $student->id],
                ['organization_id' => $session->organization_id],
            );

            if ($attendance->reminded_at !== null || $attendance->guardian_response !== null) {
                continue;
            }

            // Marca primero: si dos corridas se pisan, el update condicional gana una sola.
            $claimed = Attendance::query()->whereKey($attendance->id)->whereNull('reminded_at')->update(['reminded_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $users = ClassReminderPreference::query()
                ->where('student_id', $student->id)
                ->where('enabled', true)
                ->with('user')
                ->get()
                ->pluck('user')
                ->filter();

            Notification::send($users, new ClassReminder($session, $student, $today));
            $sent++;
        }

        return $sent;
    }
}
