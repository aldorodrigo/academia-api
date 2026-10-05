<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\AttendanceAccess;
use App\Actions\Attendance\ResolveClassSessions;
use App\Actions\Enrollments\ReportDropout;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ClassSessionResource;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Student;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Mis grupos" del técnico: clases del mes y asistencia por alumno.
 */
class GroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = AttendanceAccess::groups($request->user())
            ->where('is_active', true)
            ->with(['program', 'schedules.venue'])
            ->withCount(['enrollments as students_count' => fn (Builder $query) => $query
                ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Scholarship])
                ->whereHas('season', fn (Builder $season) => $season->active())])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $groups->map(fn (Group $group) => [
                ...$this->group($group),
                'students_count' => $group->students_count,
            ])->values(),
        ]);
    }

    public function show(Request $request, int $group, CurrentOrganization $current, ResolveClassSessions $sessions): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $group = AttendanceAccess::groups($request->user())->with(['program', 'schedules.venue'])->find($group);
        abort_if($group === null, 404, 'No encontramos este grupo.');

        $today = $current->get()->today();
        $month = $request->filled('month')
            ? CarbonImmutable::createFromFormat('!Y-m', $request->string('month'), $today->timezone)
            : $today->startOfMonth();
        $to = $month->endOfMonth()->startOfDay()->min($today);

        $classes = $month->gt($today) ? collect() : $sessions->between([$group], $month, $to)->sortByDesc(
            fn (ClassSession $session) => $session->date->toDateString().' '.$session->starts_at,
        );

        $attendances = Attendance::query()
            ->whereIn('class_session_id', $classes->pluck('id'))
            ->whereNotNull('status')
            ->get()
            ->groupBy('student_id');

        $studentIds = $classes->flatMap(fn (ClassSession $session) => $session->students()->pluck('id'))->unique();
        $students = Student::query()->whereKey($studentIds)->orderBy('last_name')->orderBy('first_name')->get();
        $reported = Enrollment::query()
            ->where('group_id', $group->id)
            ->whereIn('student_id', $studentIds)
            ->whereNotNull('dropout_reported_at')
            ->pluck('dropout_reported_at', 'student_id');
        $timezone = $current->get()->timezone;

        return response()->json([
            'data' => [
                ...$this->group($group),
                'month' => $month->format('Y-m'),
                'classes' => $classes->map(fn (ClassSession $session) => (new ClassSessionResource($session))->toArray($request))->values(),
                'students' => $students->map(function (Student $student) use ($attendances, $reported, $timezone) {
                    $marks = $attendances->get($student->id, collect())->countBy(fn (Attendance $a) => $a->status->value);

                    return [
                        'id' => $student->id,
                        'full_name' => $student->full_name,
                        'photo_url' => $student->photoUrl(),
                        ...Attendance::summary($marks->all()),
                        // El técnico avisó que dejó de venir (la baja la decide el club).
                        'dropout_reported_on' => isset($reported[$student->id])
                            ? CarbonImmutable::parse($reported[$student->id])->setTimezone($timezone)->toDateString()
                            : null,
                    ];
                })->values(),
            ],
        ]);
    }

    /**
     * "Dejó de venir": el técnico de un grupo avisa al club (nota opcional).
     */
    public function reportDropout(Request $request, int $group, int $student, ReportDropout $dropout, CurrentOrganization $current): JsonResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $enrollment = $this->enrollment($request, $group, $student);

        $enrollment = $dropout->report($enrollment, $request->input('note'), $request->user());

        return response()->json(['data' => [
            'dropout_reported_on' => $enrollment->dropout_reported_at->setTimezone($current->get()->timezone)->toDateString(),
        ]]);
    }

    /**
     * El técnico deshace su aviso: sigue viniendo.
     */
    public function cancelDropout(Request $request, int $group, int $student, ReportDropout $dropout): JsonResponse
    {
        $dropout->clear($this->enrollment($request, $group, $student), $request->user());

        return response()->json(['data' => ['dropout_reported_on' => null]]);
    }

    private function enrollment(Request $request, int $group, int $student): Enrollment
    {
        $group = AttendanceAccess::groups($request->user())->find($group);
        abort_if($group === null, 404, 'No encontramos este grupo.');

        $enrollment = ReportDropout::enrollmentFor($group, $student);
        abort_if($enrollment === null, 404, 'Este alumno no está activo en el grupo.');

        return $enrollment;
    }

    /**
     * @return array<string, mixed>
     */
    private function group(Group $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'program' => ['id' => $group->program->id, 'name' => $group->program->name],
            'schedules' => $group->schedules->sortBy('weekday')->map(fn (Schedule $schedule) => [
                'weekday' => $schedule->weekday,
                'starts_at' => Schedule::time($schedule->starts_at),
                'ends_at' => Schedule::time($schedule->ends_at),
                'venue' => $schedule->venue ? ['name' => $schedule->venue->label] : null,
            ])->values(),
        ];
    }
}
