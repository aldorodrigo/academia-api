<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\ResolveClassSessions;
use App\Actions\Attendance\RespondToClass;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ClassSessionResource;
use App\Models\Attendance;
use App\Models\ClassReminderPreference;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\Student;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Próxima clase de cada alumno a cargo del usuario y "¿Lo llevás?".
 */
class AgendaController extends Controller
{
    public const DAYS = 7;

    public function index(Request $request, CurrentOrganization $current, ResolveClassSessions $sessions): JsonResponse
    {
        $organization = $current->get();
        $now = CarbonImmutable::now($organization->timezone);
        $today = $organization->today();

        $students = Student::query()->inChargeOf($request->user())
            ->with('currentEnrollments.group.schedules')
            ->orderBy('birth_date')
            ->get();

        $items = [];

        foreach ($students as $student) {
            $groups = $student->currentEnrollments->pluck('group')->filter()->unique('id');
            $next = $this->nextClass($sessions, $groups, $student, $today, $now);

            if ($next !== null) {
                $items[] = $this->item($request, $next, $student, $now);
            }
        }

        return response()->json(['data' => $items]);
    }

    public function respond(Request $request, int $class, int $student, RespondToClass $respond): JsonResponse
    {
        $data = $request->validate(['going' => ['required', 'boolean']]);

        $student = Student::query()->inChargeOf($request->user())->find($student);
        $session = ClassSession::query()->with(['group.program', 'venue', 'organization'])->find($class);
        abort_if($student === null || $session === null, 404, 'No encontramos esta clase.');

        $respond->handle($session, $student, (bool) $data['going'], $request->user());

        return response()->json([
            'data' => $this->item($request, $session, $student, CarbonImmutable::now($session->organization->timezone)),
        ]);
    }

    /**
     * La primera clase del alumno que todavía no terminó, en los próximos días.
     *
     * @param  iterable<Group>  $groups
     */
    private function nextClass(ResolveClassSessions $sessions, iterable $groups, Student $student, CarbonImmutable $today, CarbonImmutable $now): ?ClassSession
    {
        for ($date = $today; $date->lte($today->addDays(self::DAYS)); $date = $date->addDay()) {
            $next = $sessions->forDate($groups, $date)
                ->first(fn (ClassSession $session) => $now->lt($session->endsAt())
                    && $session->students()->contains('id', $student->id));

            if ($next !== null) {
                return $next;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(Request $request, ClassSession $session, Student $student, CarbonImmutable $now): array
    {
        $attendance = Attendance::query()
            ->where('class_session_id', $session->id)
            ->where('student_id', $student->id)
            ->first();

        return [
            'student' => [
                'id' => $student->id,
                'first_name' => $student->first_name,
                'full_name' => $student->full_name,
                'photo_url' => $student->photoUrl(),
            ],
            'class' => (new ClassSessionResource($session))->withoutCounts()->toArray($request),
            'response' => $attendance?->guardian_response?->value,
            'can_respond' => ! $session->isSuspended() && ! $session->hasStarted($now),
            'class_reminders' => ClassReminderPreference::for($request->user(), $student),
        ];
    }
}
