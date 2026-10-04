<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\UnwaiveCharge;
use App\Actions\Billing\WaiveCharges;
use App\Actions\Enrollments\ReportDropout;
use App\Actions\Enrollments\WithdrawEnrollment;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChargeResource;
use App\Models\Charge;
use App\Models\ChargeCondonation;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bajas y condonación desde la app (docs/PLAN_BAJAS.md): avisos de baja, ficha para quien da de
 * baja (`withdraw_students`) o condona (`waive_charges`), y el aviso del tutor "deja el club".
 */
class WithdrawalController extends Controller
{
    public const WITHDRAW_PERMISSION = 'Update:Enrollment';

    public function __construct(private CurrentOrganization $current) {}

    public static function canWithdraw(User $user): bool
    {
        return $user->can(self::WITHDRAW_PERMISSION);
    }

    /**
     * Avisos de baja sin decidir (técnico o tutor), el más viejo primero.
     */
    public function dropoutReports(Request $request): JsonResponse
    {
        abort_unless(self::canWithdraw($request->user()), 403, 'No podés dar de baja.');

        $enrollments = Enrollment::query()
            ->whereNotNull('dropout_reported_at')
            ->with(['student', 'group.program', 'organization', 'dropoutReportedBy'])
            ->orderBy('dropout_reported_at')
            ->get();

        return response()->json(['data' => $enrollments->map(fn (Enrollment $enrollment) => [
            'enrollment_id' => $enrollment->id,
            'student' => ['id' => $enrollment->student->id, 'full_name' => $enrollment->student->full_name],
            'group' => $enrollment->group->name,
            'program' => $enrollment->group->program?->name,
            ...$enrollment->dropoutReport(),
        ])->values()]);
    }

    /**
     * Ficha del alumno para quien da de baja o condona.
     */
    public function student(Request $request, int $student): JsonResponse
    {
        $user = $request->user();
        $waives = WaiveCharges::allows($user);
        abort_unless(self::canWithdraw($user) || $waives, 403, 'No podés ver esta ficha.');

        $student = Student::query()->with(['currentEnrollments.group.program', 'currentEnrollments.season', 'currentEnrollments.organization', 'currentEnrollments.dropoutReportedBy'])->find($student);
        abort_if($student === null, 404, 'No encontramos a este alumno.');

        $enrollments = $student->currentEnrollments->sortBy('id');
        $first = $enrollments->first();
        $charges = $waives ? $this->charges($student) : null;

        return response()->json(['data' => [
            'id' => $student->id,
            'full_name' => $student->full_name,
            'first_name' => $student->first_name,
            'enrollments' => $enrollments->map(fn (Enrollment $enrollment) => [
                'id' => $enrollment->id,
                'program' => $enrollment->group->program?->name,
                'group' => $enrollment->group->name,
                'season' => $enrollment->season->name,
                'status' => $enrollment->status->value,
                'status_label' => $enrollment->statusLabel(),
                'enrolled_on' => $enrollment->enrolled_on?->toDateString(),
                'ended_on' => $enrollment->ended_on?->toDateString(),
                'withdrawal_reason' => $enrollment->withdrawal_reason,
                'can_withdraw' => ! $enrollment->isWithdrawn() && ! $enrollment->isFinished() && $user->can('update', $enrollment),
                'dropout_report' => $enrollment->dropoutReport(),
            ])->values(),
            'notice' => [
                'recipients' => WithdrawEnrollment::noticeRecipients($student)->count(),
                'message' => $first ? WithdrawEnrollment::defaultNotice($first) : null,
            ],
            'charges' => $charges?->map(fn (Charge $charge) => $this->charge($request, $charge))->values(),
            'balance' => (int) ($charges ?? collect())
                ->reject(fn (Charge $charge) => $charge->isVoided() || $charge->isUpcoming())
                ->sum(fn (Charge $charge) => $charge->pendingAmount()),
        ]]);
    }

    /**
     * Dar de baja con fecha, motivo y, si se elige, el aviso a la familia.
     */
    public function withdraw(Request $request, int $enrollment, WithdrawEnrollment $withdraw): JsonResponse
    {
        $enrollment = Enrollment::query()->with(['student', 'organization', 'season'])->find($enrollment);
        abort_if($enrollment === null, 404, 'No encontramos esta inscripción.');
        abort_unless($request->user()->can('update', $enrollment), 403, 'No podés dar de baja.');

        $data = $request->validate([
            'ended_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'notify' => ['boolean'],
            'message' => ['nullable', 'required_if:notify,true', 'string', 'max:1000'],
        ], ['message.required_if' => 'Escribí el mensaje para la familia.']);

        $notify = (bool) ($data['notify'] ?? false);
        $withdraw->handle($enrollment, $data['ended_on'], $data['reason'], $request->user(), $notify ? $data['message'] : null);

        return response()->json(['data' => [
            'status' => $enrollment->status->value,
            'notified' => $notify ? WithdrawEnrollment::noticeRecipients($enrollment->student)->count() : 0,
        ]]);
    }

    /**
     * "Sigue viniendo": descarta el aviso de baja.
     */
    public function dismissDropout(Request $request, int $enrollment, ReportDropout $dropout): JsonResponse
    {
        $enrollment = Enrollment::query()->find($enrollment);
        abort_if($enrollment === null, 404, 'No encontramos esta inscripción.');
        abort_unless($request->user()->can('update', $enrollment), 403, 'No podés dar de baja.');

        $dropout->clear($enrollment, $request->user());

        return response()->json(['data' => ['dropout_report' => null]]);
    }

    public function waive(Request $request, WaiveCharges $waive): JsonResponse
    {
        WaiveCharges::authorize($request->user());
        $data = $request->validate([
            'charge_ids' => ['required', 'array', 'min:1'],
            'charge_ids.*' => ['integer'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $charges = Charge::query()->whereKey($data['charge_ids'])->with(['allocations.payment', 'organization'])->get();
        abort_if($charges->count() !== count(array_unique($data['charge_ids'])), 404, 'No encontramos alguna de las cuotas.');

        return response()->json(['data' => ['waived' => $waive->handle($charges, $data['reason'], $request->user())]]);
    }

    public function unwaive(Request $request, int $charge, UnwaiveCharge $unwaive): JsonResponse
    {
        WaiveCharges::authorize($request->user());
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $charge = Charge::query()->find($charge);
        abort_if($charge === null, 404, 'No encontramos esta cuota.');

        $charge = $unwaive->handle($charge, $data['reason'], $request->user());

        return response()->json(['data' => $this->charge($request, $charge->load(['student', 'feeConcept', 'group', 'season', 'adjustments', 'organization', 'allocations.payment']))]);
    }

    /**
     * El tutor avisa que su hijo deja el club (mensaje opcional). Lo decide quien da de baja.
     */
    public function leaving(Request $request, int $student, ReportDropout $dropout): JsonResponse
    {
        $data = $request->validate(['message' => ['nullable', 'string', 'max:500']]);
        $student = $this->ownStudent($request, $student);

        abort_if(ReportDropout::leavingEnrollments($student)->isEmpty(), 422, 'No tiene inscripciones vigentes.');

        $enrollment = $dropout->reportLeaving($student, $data['message'] ?? null, $request->user());

        return response()->json(['data' => [
            'leaving_reported_on' => $enrollment->dropout_reported_at->setTimezone($this->current->get()->timezone)->toDateString(),
        ]]);
    }

    public function cancelLeaving(Request $request, int $student, ReportDropout $dropout): JsonResponse
    {
        $dropout->cancelLeaving($this->ownStudent($request, $student), $request->user());

        return response()->json(['data' => ['leaving_reported_on' => null]]);
    }

    private function ownStudent(Request $request, int $student): Student
    {
        $student = Student::query()->inChargeOf($request->user())->find($student);
        abort_if($student === null, 404, 'No encontramos a este alumno.');

        return $student;
    }

    /**
     * Cuotas sin anular y las condonadas (para deshacer), las más nuevas primero.
     *
     * @return Collection<int, Charge>
     */
    private function charges(Student $student)
    {
        return Charge::query()
            ->where('student_id', $student->id)
            ->where(fn (Builder $query) => $query->whereNull('voided_at')->orWhereNotNull('waived_amount'))
            ->with(['student', 'feeConcept', 'group', 'season', 'adjustments', 'organization', 'allocations.payment', 'condonations.creator'])
            ->orderByDesc('due_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(Request $request, Charge $charge): array
    {
        /** @var ChargeCondonation|null $condonation */
        $condonation = $charge->isWaived() ? $charge->condonations->first(fn (ChargeCondonation $c) => ! $c->isUndone()) : null;
        $timezone = $charge->organization->timezone;

        return [
            ...(new ChargeResource($charge))->toArray($request),
            'pending_amount' => $charge->isVoided() ? 0 : $charge->pendingAmount(),
            'waiver' => $charge->isWaived() ? [
                'amount' => $charge->waived_amount,
                'reason' => $charge->void_reason,
                'by' => $condonation?->creator?->name,
                'on' => $charge->voided_at->setTimezone($timezone)->toDateString(),
            ] : null,
            'can_waive' => WaiveCharges::canWaive($charge),
            'can_unwaive' => $charge->isWaived(),
        ];
    }
}
