<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\AttendanceAccess;
use App\Actions\Attendance\ResolveClassSessions;
use App\Actions\Enrollments\EnrollmentRequestAccess;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ClassSessionResource;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\EnrollmentRequest;
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

        // Nuevos que pidieron lugar desde la app: en el mes en curso aparecen aunque todavía no hayan tenido clase.
        $pending = EnrollmentRequest::query()->pending()->where('group_id', $group->id)->whereNotNull('student_id')->pluck('id', 'student_id');
        if ($month->isSameMonth($today)) {
            $studentIds = $studentIds->merge($pending->keys())->unique();
        }
        $canReview = $pending->isNotEmpty() && EnrollmentRequestAccess::canReviewGroup($request->user(), $group);

        $students = Student::query()->whereKey($studentIds)->orderBy('last_name')->orderBy('first_name')->get();

        return response()->json([
            'data' => [
                ...$this->group($group),
                'month' => $month->format('Y-m'),
                'classes' => $classes->map(fn (ClassSession $session) => (new ClassSessionResource($session))->toArray($request))->values(),
                'students' => $students->map(function (Student $student) use ($attendances, $pending, $canReview) {
                    $marks = $attendances->get($student->id, collect())->countBy(fn (Attendance $a) => $a->status->value);

                    return [
                        'id' => $student->id,
                        'full_name' => $student->full_name,
                        'photo_url' => $student->photoUrl(),
                        ...Attendance::summary($marks->all()),
                        'enrollment_request' => isset($pending[$student->id])
                            ? ['id' => $pending[$student->id], 'can_review' => $canReview]
                            : null,
                    ];
                })->values(),
            ],
        ]);
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
