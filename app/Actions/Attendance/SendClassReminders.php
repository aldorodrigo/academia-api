<?php

namespace App\Actions\Attendance;

use App\Actions\Lessons\LessonAccess;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Models\Attendance;
use App\Models\Booking;
use App\Models\ClassReminderLog;
use App\Models\ClassReminderPreference;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\LessonProfile;
use App\Models\LessonReminderLog;
use App\Models\NotificationSetting;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use App\Notifications\ClassReminder;
use App\Notifications\InstructorClassReminder;
use App\Notifications\LessonReminder;
use App\Notifications\TeacherDayReminder;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Avisos de días de clase (corre cada 15 minutos):
 *  - al tutor que lo pidió para ese hijo: "Hoy Mateo tiene Fútbol a las 17:00. ¿Lo llevás?"
 *    (uno por usuario y clase aunque tenga varios hijos en ella; si ya respondió, no se le avisa);
 *  - al técnico del grupo (activado por defecto): "Hoy tenés clase con Sub-10 · 15 van…";
 *  - clases particulares (módulo `private_lessons`): al alumno o tutor por cada reserva y al
 *    profesor un resumen del día ("Hoy tenés 3 clases: 16:00 Mateo, …"), contado desde la primera.
 *
 * Cada usuario elige hasta 3 momentos (NotificationSetting; si no, los del club). Un aviso que
 * caería entre las 22:00 y las 7:00 sale a las 20:00 del día anterior; los que caen en el mismo
 * momento, o que se atrasaron, salen una sola vez. Idempotente por `class_reminder_logs`.
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
     * @return int avisos enviados (notificaciones)
     */
    public function handle(Organization $organization, ?CarbonImmutable $now = null): int
    {
        return $this->current->run($organization, function (Organization $organization) use ($now) {
            $now = ($now ?? CarbonImmutable::now())->setTimezone($organization->timezone);
            $today = $now->startOfDay();

            $optedIn = ClassReminderPreference::query()->where('enabled', true)->get()->groupBy('student_id');

            $groups = Group::query()
                ->where('is_active', true)
                ->where(fn (Builder $query) => $query
                    ->whereHas('instructors')
                    ->orWhereHas('enrollments', fn (Builder $enrollments) => $enrollments->whereIn('student_id', $optedIn->keys())))
                ->with(['schedules', 'instructors'])
                ->get();

            $sent = 0;

            foreach ($this->sessions->between($groups, $today, $today->addDay()) as $session) {
                if ($session->isOff() || $session->hasStarted($now)) {
                    continue;
                }

                $sent += $this->remindGuardians($session, $optedIn, $organization, $now, $today);
                $sent += $this->remindInstructors($session, $groups->firstWhere('id', $session->group_id), $organization, $now, $today);
            }

            if ($organization->hasFeature(Feature::PrivateLessons)) {
                $sent += $this->remindBookings($organization, $now, $today);
            }

            return $sent;
        });
    }

    /**
     * Cuándo sale un aviso de la clase: minutos antes o "eve" (día anterior 20:00), con la regla nocturna.
     */
    public static function sendAt(ClassSession $session, int|string $offset): CarbonImmutable
    {
        return self::sendBefore($session->startsAt(), $offset);
    }

    /**
     * Lo mismo para cualquier hora de inicio (clases particulares).
     */
    public static function sendBefore(CarbonImmutable $startsAt, int|string $offset): CarbonImmutable
    {
        if ($offset === NotificationSetting::EVE) {
            return $startsAt->subDay()->setTime(self::EVENING, 0);
        }

        $at = $startsAt->subMinutes((int) $offset);

        return match (true) {
            $at->hour < self::QUIET_UNTIL => $at->subDay()->setTime(self::EVENING, 0),
            $at->hour >= self::QUIET_FROM => $at->setTime(self::EVENING, 0),
            default => $at,
        };
    }

    /**
     * @param  Collection<int, Collection<int, ClassReminderPreference>>  $optedIn  por alumno
     */
    private function remindGuardians(ClassSession $session, Collection $optedIn, Organization $organization, CarbonImmutable $now, CarbonImmutable $today): int
    {
        $students = $session->students()->filter(fn (Student $student) => $optedIn->has($student->id));

        if ($students->isEmpty()) {
            return 0;
        }

        $responded = Attendance::query()
            ->where('class_session_id', $session->id)
            ->whereNotNull('guardian_response')
            ->pluck('student_id')
            ->flip();

        // Por usuario, sus hijos en esta clase que todavía no respondieron.
        $byUser = [];
        foreach ($students as $student) {
            if ($responded->has($student->id)) {
                continue;
            }
            foreach ($optedIn->get($student->id) as $preference) {
                $byUser[$preference->user_id][] = $student;
            }
        }

        $sent = 0;

        foreach ($byUser as $userId => $userStudents) {
            $user = User::query()->find($userId);
            if ($user === null) {
                continue;
            }

            $offsets = NotificationSetting::for($user, $organization)->guardianOffsets($organization);
            $studentIds = collect($userStudents)->pluck('id')->all();

            if (! $this->claim($session, $user, $studentIds, $offsets, $now)) {
                continue;
            }

            Notification::send($user, new ClassReminder($session, collect($userStudents), $today, $user->id));
            $sent++;
        }

        return $sent;
    }

    private function remindInstructors(ClassSession $session, ?Group $group, Organization $organization, CarbonImmutable $now, CarbonImmutable $today): int
    {
        $sent = 0;

        foreach ($group?->instructors ?? [] as $instructor) {
            $settings = NotificationSetting::for($instructor, $organization);

            if (! $settings->instructor_enabled) {
                continue;
            }

            if (! $this->claim($session, $instructor, [0], $settings->instructorOffsets($organization), $now)) {
                continue;
            }

            Notification::send($instructor, new InstructorClassReminder($session, $today));
            $sent++;
        }

        return $sent;
    }

    /**
     * Registra los avisos ya vencidos que faltaban (para cada alumno, o 0 para el técnico).
     * True si hay que mandar uno ahora: los atrasados o simultáneos salen juntos, una sola vez.
     *
     * @param  list<int>  $studentIds
     * @param  list<int|string>  $offsets
     */
    private function claim(ClassSession $session, User $user, array $studentIds, array $offsets, CarbonImmutable $now): bool
    {
        $due = array_values(array_filter($offsets, fn ($offset) => ! $now->lt(self::sendAt($session, $offset))));

        if ($due === []) {
            return false;
        }

        return DB::transaction(function () use ($session, $user, $studentIds, $due, $now) {
            $claimed = false;

            foreach ($studentIds as $studentId) {
                foreach ($due as $offset) {
                    $created = ClassReminderLog::query()->insertOrIgnore([
                        'organization_id' => $session->organization_id,
                        'class_session_id' => $session->id,
                        'user_id' => $user->id,
                        'student_id' => $studentId,
                        'offset' => (string) $offset,
                        'sent_at' => $now->utc(),
                    ]);
                    $claimed = $claimed || $created > 0;
                }
            }

            return $claimed;
        });
    }

    /**
     * Reservas de hoy y mañana: aviso al alumno o tutor (sus momentos de tutor) y resumen del día
     * al profesor (sus momentos de técnico, si los tiene activados).
     */
    private function remindBookings(Organization $organization, CarbonImmutable $now, CarbonImmutable $today): int
    {
        $bookings = Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereBetween('date', [$today->toDateString(), $today->addDay()->toDateString()])
            ->with(['student.guardians', 'teacher', 'organization'])
            ->orderBy('date')
            ->orderBy('starts_at')
            ->get()
            ->reject(fn (Booking $booking) => $booking->hasStarted($now));

        $sent = 0;

        foreach ($bookings as $booking) {
            foreach (LessonAccess::recipients($booking->student) as $user) {
                $offsets = NotificationSetting::for($user, $organization)->guardianOffsets($organization);

                if ($this->claimLesson($organization, "bkg:{$booking->id}:u:{$user->id}", $booking->startsAt(), $offsets, $now)) {
                    $user->notify(new LessonReminder($booking, $booking->student->user_id === $user->id));
                    $sent++;
                }
            }
        }

        $teaching = LessonProfile::query()->enabled()->pluck('user_id')->flip();

        foreach ($bookings->groupBy(fn (Booking $booking) => $booking->user_id.'|'.$booking->date->toDateString()) as $day) {
            $teacher = $day->first()->teacher;

            if ($teacher === null || ! $teaching->has($teacher->id)) {
                continue;
            }

            $settings = NotificationSetting::for($teacher, $organization);

            if (! $settings->instructor_enabled) {
                continue;
            }

            $key = 'day:'.$day->first()->date->toDateString().":u:{$teacher->id}";

            if ($this->claimLesson($organization, $key, $day->first()->startsAt(), $settings->instructorOffsets($organization), $now)) {
                $teacher->notify(new TeacherDayReminder($day->values()));
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Como claim(), con la clave del aviso de clase particular.
     *
     * @param  list<int|string>  $offsets
     */
    private function claimLesson(Organization $organization, string $key, CarbonImmutable $startsAt, array $offsets, CarbonImmutable $now): bool
    {
        $claimed = false;

        foreach ($offsets as $offset) {
            if ($now->lt(self::sendBefore($startsAt, $offset))) {
                continue;
            }

            $created = LessonReminderLog::query()->insertOrIgnore([
                'organization_id' => $organization->id,
                'reminder_key' => "{$key}:{$offset}",
                'sent_at' => $now->utc(),
            ]);
            $claimed = $claimed || $created > 0;
        }

        return $claimed;
    }
}
