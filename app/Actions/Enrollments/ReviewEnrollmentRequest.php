<?php

namespace App\Actions\Enrollments;

use App\Actions\Students\RegisterStudent;
use App\Enums\EnrollmentRequestStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MidPeriod;
use App\Enums\OrganizationRole;
use App\Exceptions\ImportRowException;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\EnrollmentRequest;
use App\Models\Group;
use App\Models\MedicalRecord;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\EnrollmentRequestReviewed;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirmar una solicitud pasa la inscripción pendiente a `activo` (se emiten el cargo de inscripción y las
 * cuotas del plan desde el día en que empezó, con el `MidPeriod` elegido); rechazarla (o que el tutor la
 * cancele) deshace el alta: el chico sale de la lista. Siempre queda quién la confirmó o la rechazó.
 */
class ReviewEnrollmentRequest
{
    public function __construct(
        private RegisterStudent $register,
        private RoleAssigner $roles,
        private CurrentOrganization $current,
    ) {}

    /**
     * @param  Group|null  $group  otra categoría de la misma disciplina (null: la pedida)
     * @param  MidPeriod|null  $midPeriod  qué se cobra del período en curso (null: lo del plan)
     * @param  bool  $overCapacity  confirmar aunque la categoría esté completa
     */
    public function approve(
        EnrollmentRequest $request,
        User $by,
        ?Group $group = null,
        ?MidPeriod $midPeriod = null,
        bool $overCapacity = false,
    ): EnrollmentRequest {
        $organization = Organization::query()->findOrFail($request->organization_id);

        $request = $this->current->run($organization, fn () => DB::transaction(function () use ($request, $organization, $by, $group, $midPeriod, $overCapacity) {
            $request = $this->lockPending($request);
            $season = $request->season;
            $group ??= $request->group;
            $enrollment = $request->enrollment;

            if ($enrollment === null || $request->student === null) {
                throw ValidationException::withMessages(['status' => 'La inscripción de esta solicitud ya no existe.']);
            }

            if ($season->hasEnded($organization->today())) {
                throw ValidationException::withMessages(['season_id' => "La temporada {$season->name} ya terminó."]);
            }

            if ($group->program_id !== $request->group->program_id || ! $group->is_active) {
                throw ValidationException::withMessages(['group_id' => "Elegí una categoría de {$request->group->program->name}."]);
            }

            // La inscripción pendiente ya ocupa su lugar: está completa si no queda lugar sin contarla.
            if (EnrollmentRequestAccess::capacity($group, $season, $enrollment->id)['full'] && ! $overCapacity) {
                throw ValidationException::withMessages([
                    'over_capacity' => "{$group->name} está completa ({$group->capacity} de {$group->capacity}). Confirmá para inscribirla igual.",
                ]);
            }

            if ($enrollment->group_id !== $group->id) {
                $enrollment->update(['group_id' => $group->id]);
            }

            if (! $request->guardian_linked) {
                $this->linkGuardian($organization, $request, $group, $by);
            }

            // Pendiente → activo: el modelo emite el cargo de inscripción y las cuotas (business-logic.md §5).
            $enrollment->fill(['status' => EnrollmentStatus::Active, 'mid_period' => $midPeriod ?? $enrollment->mid_period])->save();

            if ($request->medical !== null && $request->student->medicalRecord()->doesntExist()) {
                MedicalRecord::query()->create([...$request->medical, 'student_id' => $request->student_id]);
            }

            if (! $request->user->hasCurrentRole($organization, OrganizationRole::Guardian)) {
                $this->roles->assign($organization, $request->user, OrganizationRole::Guardian, assignedBy: $by);
            }

            $request->update([
                'status' => EnrollmentRequestStatus::Approved,
                'group_id' => $group->id,
                'guardian_linked' => true,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                // Ya está en la ficha del alumno: no se guarda dos veces.
                'medical' => null,
            ]);

            return $request;
        }));

        // Se confirmó sola (la pidió quien puede confirmar): no hace falta avisarle.
        if ($by->id !== $request->user_id) {
            $request->user->notify(new EnrollmentRequestReviewed($request->load(['group.program', 'season'])));
        }

        return $request;
    }

    public function reject(EnrollmentRequest $request, User $by, string $reason): EnrollmentRequest
    {
        $request = $this->close($request, EnrollmentRequestStatus::Rejected, $by, trim($reason));

        $request->user->notify(new EnrollmentRequestReviewed($request->load(['group.program', 'season'])));

        return $request;
    }

    /**
     * El tutor la retira mientras está por confirmar (sin aviso).
     */
    public function cancel(EnrollmentRequest $request): EnrollmentRequest
    {
        return $this->close($request, EnrollmentRequestStatus::Cancelled, null, null);
    }

    /**
     * Rechazar o cancelar: el chico sale de la lista. Nada se borra: se archiva (soft delete) la inscripción
     * pendiente que creó la solicitud (o vuelve al estado que tenía, ej. baja) y, si el alumno lo creó (o lo
     * restauró) la solicitud y no tiene nada más, también el alumno y sus asistencias. Si vuelve a pedirlo, se
     * restauran con su historial.
     */
    private function close(EnrollmentRequest $request, EnrollmentRequestStatus $status, ?User $by, ?string $reason): EnrollmentRequest
    {
        $organization = Organization::query()->findOrFail($request->organization_id);

        return $this->current->run($organization, fn () => DB::transaction(function () use ($request, $status, $by, $reason) {
            $request = $this->lockPending($request);
            $enrollment = $request->enrollment;
            $student = $request->student;

            if ($enrollment !== null && $enrollment->status === EnrollmentStatus::Pending) {
                $request->previous_enrollment_status === null
                    ? $enrollment->delete()
                    : $enrollment->update(['status' => $request->previous_enrollment_status]);
            }

            $request->update([
                'status' => $status,
                'reviewed_by' => $by?->id,
                'reviewed_at' => $by ? now() : null,
                'rejection_reason' => $reason,
                'medical' => null,
            ]);

            if ($request->student_created && $student !== null
                && Enrollment::query()->where('student_id', $student->id)->doesntExist()
                && Charge::query()->where('student_id', $student->id)->doesntExist()) {
                $student->attendances()->delete();
                $student->delete();
            }

            return $request->refresh();
        }));
    }

    /**
     * Chico de otra familia: el tutor que lo pidió se suma recién ahora, con el mismo alta de siempre.
     */
    private function linkGuardian(Organization $organization, EnrollmentRequest $request, Group $group, User $by): void
    {
        try {
            $this->register->handle(
                $organization,
                SubmitEnrollmentRequest::studentData($request->student),
                $group,
                $request->season,
                null,
                [SubmitEnrollmentRequest::guardianRow($request->user, $request->relationship)],
                $by,
            );
        } catch (ImportRowException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }
    }

    /**
     * Relee con lock: dos personas no la revisan a la vez.
     */
    private function lockPending(EnrollmentRequest $request): EnrollmentRequest
    {
        $request = EnrollmentRequest::query()->withoutGlobalScopes()
            ->with(['user', 'season', 'group.program', 'enrollment', 'student'])
            ->lockForUpdate()
            ->findOrFail($request->id);

        if (! $request->isPending()) {
            throw ValidationException::withMessages(['status' => 'Esta solicitud ya fue revisada.']);
        }

        return $request;
    }
}
