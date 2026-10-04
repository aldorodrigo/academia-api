<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\ResolveClassSessions;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ClassSessionResource;
use App\Models\Attendance;
use App\Models\ClassReminderPreference;
use App\Models\ClassSession;
use App\Models\Student;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asistencia de un hijo y el aviso de sus días de clase.
 */
class StudentAttendanceController extends Controller
{
    public function show(Request $request, int $student, CurrentOrganization $current, ResolveClassSessions $sessions): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $student = $this->find($request, $student);

        $today = $current->get()->today();
        $month = $request->filled('month')
            ? CarbonImmutable::createFromFormat('!Y-m', $request->string('month'), $today->timezone)
            : $today->startOfMonth();
        $to = $month->endOfMonth()->startOfDay()->min($today);

        $groups = $student->enrollments()->with('group.schedules')->get()->pluck('group')->filter()->unique('id');
        $classes = $month->gt($today) ? collect() : $sessions->between($groups, $month, $to)
            ->filter(fn (ClassSession $session) => $session->students()->contains('id', $student->id))
            ->sortByDesc(fn (ClassSession $session) => $session->date->toDateString().' '.$session->starts_at)
            ->values();

        $attendances = Attendance::query()
            ->where('student_id', $student->id)
            ->whereIn('class_session_id', $classes->pluck('id'))
            ->get()
            ->keyBy('class_session_id');

        $marks = $attendances->filter(fn (Attendance $a) => $a->status !== null)->countBy(fn (Attendance $a) => $a->status->value);

        return response()->json([
            'data' => [
                'month' => $month->format('Y-m'),
                ...Attendance::summary($marks->all()),
                'classes' => $classes->map(fn (ClassSession $session) => [
                    ...(new ClassSessionResource($session))->withoutCounts()->toArray($request),
                    'attendance' => $attendances->get($session->id)?->status?->value,
                ])->values(),
            ],
        ]);
    }

    public function reminders(Request $request, int $student): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $student = $this->find($request, $student);

        ClassReminderPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'student_id' => $student->id],
            ['enabled' => (bool) $data['enabled']],
        );

        return response()->json(['data' => ['class_reminders' => (bool) $data['enabled']]]);
    }

    private function find(Request $request, int $id): Student
    {
        $student = Student::query()->inChargeOf($request->user())->find($id);
        abort_if($student === null, 404, 'No encontramos a este alumno.');

        return $student;
    }
}
