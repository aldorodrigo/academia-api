<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\AttendanceAccess;
use App\Actions\Attendance\RecordAttendance;
use App\Actions\Attendance\RescheduleClass;
use App\Actions\Attendance\ResolveClassSessions;
use App\Actions\Attendance\SuspendClass;
use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ClassSessionResource;
use App\Models\ClassSession;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Asistencia desde la app del técnico: clases de sus grupos.
 */
class ClassController extends Controller
{
    public function index(Request $request, CurrentOrganization $current, ResolveClassSessions $sessions): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $today = $current->get()->today();
        $date = $request->filled('date')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $request->string('date'), $today->timezone)
            : $today;

        $classes = $sessions->forDate(AttendanceAccess::groups($request->user())->with('schedules')->get(), $date);

        return response()->json([
            'data' => $classes->map(fn (ClassSession $session) => (new ClassSessionResource($session))->toArray($request))->values(),
        ]);
    }

    public function show(Request $request, int $class): JsonResponse
    {
        return $this->respond($request, $this->find($request, $class));
    }

    public function attendance(Request $request, int $class, RecordAttendance $record): JsonResponse
    {
        $session = $this->find($request, $class);

        $data = $request->validate([
            'marks' => ['required', 'array'],
            'marks.*.student_id' => ['required', 'integer'],
            'marks.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'marks.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $record->handle($session, $data['marks'], $request->user());

        return $this->respond($request, $session->refresh());
    }

    public function suspend(Request $request, int $class, SuspendClass $suspend): JsonResponse
    {
        $session = $this->find($request, $class);
        abort_if($session->isPast(), 422, 'Una clase que ya pasó no se puede suspender.');

        abort_if($session->isRescheduled(), 422, 'La clase está reprogramada: primero cancelá la reprogramación.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'waive_charge' => ['sometimes', 'boolean'],
        ]);
        $suspend->handle($session, $data['reason'], $request->user(), (bool) ($data['waive_charge'] ?? false));

        return $this->respond($request, $session->refresh());
    }

    public function resume(Request $request, int $class, SuspendClass $suspend): JsonResponse
    {
        $session = $this->find($request, $class);
        abort_unless($session->isSuspended(), 422, 'La clase no está suspendida.');
        $suspend->resume($session);

        return $this->respond($request, $session->refresh());
    }

    public function reschedule(Request $request, int $class, RescheduleClass $reschedule): JsonResponse
    {
        $session = $this->find($request, $class);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->where('organization_id', $session->organization_id)],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $reschedule->handle($session, $data, $request->user());

        return $this->respond($request, $session->refresh());
    }

    public function cancelReschedule(Request $request, int $class, RescheduleClass $reschedule): JsonResponse
    {
        $session = $this->find($request, $class);
        $reschedule->cancel($session, $request->user());

        return $this->respond($request, $session->refresh());
    }

    /**
     * 404 también para clases de grupos que el usuario no dirige.
     */
    private function find(Request $request, int $id): ClassSession
    {
        $session = ClassSession::query()->with(['group.program', 'venue', 'organization', 'rescheduledTo.venue', 'rescheduledFrom.venue'])->find($id);

        abort_if($session === null || ! AttendanceAccess::allows($request->user(), $session), 404, 'No encontramos esta clase.');

        return $session;
    }

    private function respond(Request $request, ClassSession $session): JsonResponse
    {
        return response()->json(['data' => (new ClassSessionResource($session))->detailed()->toArray($request)]);
    }
}
