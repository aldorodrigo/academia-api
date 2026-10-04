<?php

namespace App\Actions\Enrollments;

use App\Actions\Students\RegisterStudent;
use App\Enums\EnrollmentRequestStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MidPeriod;
use App\Enums\OrganizationRole;
use App\Exceptions\ImportRowException;
use App\Models\EnrollmentRequest;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\MedicalRecord;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use App\Notifications\EnrollmentRequestReviewed;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aprobar una solicitud da de alta al chico con `RegisterStudent` (alumno reutilizado si ya existe, tutor
 * vinculado a la cuenta que la pidió, familia, inscripción activa y cuotas del plan); rechazarla deja el
 * motivo. En los dos casos se avisa al tutor. El tutor la puede cancelar mientras está pendiente.
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

            if ($season->hasEnded($organization->today())) {
                throw ValidationException::withMessages(['season_id' => "La temporada {$season->name} ya terminó."]);
            }

            if ($group->program_id !== $request->group->program_id || ! $group->is_active) {
                throw ValidationException::withMessages(['group_id' => "Elegí una categoría de {$request->group->program->name}."]);
            }

            $capacity = EnrollmentRequestAccess::capacity($group, $season);
            if ($capacity['full'] && ! $overCapacity) {
                throw ValidationException::withMessages([
                    'over_capacity' => "{$group->name} está completa ({$group->capacity} de {$group->capacity}). Confirmá para inscribirla igual.",
                ]);
            }

            $student = $this->register($organization, $request, $group, $by, $midPeriod);

            if ($request->medical !== null && $student->medicalRecord()->doesntExist()) {
                MedicalRecord::query()->create([...$request->medical, 'student_id' => $student->id]);
            }

            if (! $request->user->hasCurrentRole($organization, OrganizationRole::Guardian)) {
                $this->roles->assign($organization, $request->user, OrganizationRole::Guardian, assignedBy: $by);
            }

            $request->update([
                'status' => EnrollmentRequestStatus::Approved,
                'group_id' => $group->id,
                'student_id' => $student->id,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                // Ya está en la ficha del alumno: no se guarda dos veces.
                'medical' => null,
            ]);

            return $request;
        }));

        $request->user->notify(new EnrollmentRequestReviewed($request->load(['group.program', 'season'])));

        return $request;
    }

    public function reject(EnrollmentRequest $request, User $by, string $reason): EnrollmentRequest
    {
        $request = DB::transaction(function () use ($request, $by, $reason) {
            $request = $this->lockPending($request);
            $request->update([
                'status' => EnrollmentRequestStatus::Rejected,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'rejection_reason' => trim($reason),
                'medical' => null,
            ]);

            return $request;
        });

        $request->user->notify(new EnrollmentRequestReviewed($request->load(['group.program', 'season'])));

        return $request;
    }

    /**
     * El tutor la retira mientras está pendiente (sin aviso).
     */
    public function cancel(EnrollmentRequest $request): EnrollmentRequest
    {
        return DB::transaction(function () use ($request) {
            $request = $this->lockPending($request);
            $request->update(['status' => EnrollmentRequestStatus::Cancelled, 'medical' => null]);

            return $request;
        });
    }

    /**
     * Alta con el mismo camino que el panel y la importación. El tutor es la cuenta que la pidió: su ficha de
     * tutor si ya tiene una (sin pisar sus datos) o una nueva con su nombre y sus datos verificados.
     */
    private function register(Organization $organization, EnrollmentRequest $request, Group $group, User $by, ?MidPeriod $midPeriod): Student
    {
        $user = $request->user;
        $guardian = Guardian::query()->where('user_id', $user->id)->first();
        [$firstName, $lastName] = $guardian
            ? [$guardian->first_name, $guardian->last_name]
            : array_pad(explode(' ', trim($user->name), 2), 2, '');

        try {
            return $this->register->handle(
                $organization,
                [
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'document' => $request->document,
                    'birth_date' => $request->birth_date,
                ],
                $group,
                $request->season,
                EnrollmentStatus::Active,
                [[
                    'user_id' => $user->id,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $guardian ? null : ($user->email_verified_at ? $user->email : null),
                    'phone' => $guardian ? null : ($user->phone_verified_at ? $user->phone : null),
                    'relationship' => $request->relationship->value,
                    'invite' => false,
                ]],
                $by,
                midPeriod: $midPeriod,
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
            ->with(['user', 'season', 'group.program'])
            ->lockForUpdate()
            ->findOrFail($request->id);

        if (! $request->isPending()) {
            throw ValidationException::withMessages(['status' => 'Esta solicitud ya fue revisada.']);
        }

        return $request;
    }
}
