<?php

namespace App\Actions\Students;

use App\Actions\Invitations\CreateInvitation;
use App\Enums\EnrollmentStatus;
use App\Enums\GuardianRelationship;
use App\Exceptions\ImportRowException;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Organization;
use App\Models\Season;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un jugador en un solo paso: datos, inscripción, tutores (con familia
 * automática) e invitación a la app. La usan el formulario del panel y la importación.
 *
 * No duplica: el alumno se busca por documento (o nombre + fecha de nacimiento) y el
 * tutor por correo, documento o nombre dentro de la familia.
 */
class RegisterStudent
{
    /** Invitaciones enviadas en la última llamada. */
    public int $invited = 0;

    public function __construct(
        private CurrentOrganization $current,
        private CreateInvitation $invitations,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, birth_date: CarbonImmutable|string, document?: ?string, shirt_size?: ?string, position?: ?string, notes?: ?string, user_id?: ?int}  $data
     * @param  list<array{first_name?: ?string, last_name?: ?string, document?: ?string, email?: ?string, phone?: ?string, relationship?: ?string, invite?: bool}>  $guardians
     * @param  EnrollmentStatus|null  $status  null: se mantiene el de una inscripción existente (o Activo si es nueva)
     * @param  bool  $mustBeNew  el formulario "Nuevo jugador" no reutiliza un jugador existente (la importación sí)
     */
    public function handle(
        Organization $organization,
        array $data,
        Group $group,
        Season $season,
        ?EnrollmentStatus $status,
        array $guardians = [],
        ?User $invitedBy = null,
        bool $mustBeNew = false,
    ): Student {
        $this->invited = 0;

        return $this->current->run($organization, function () use ($data, $group, $season, $status, $guardians, $invitedBy, $mustBeNew) {
            [$student, $toInvite] = DB::transaction(function () use ($data, $group, $season, $status, $guardians, $mustBeNew) {
                $student = $this->student($data, $mustBeNew);
                $toInvite = $this->guardians($student, $guardians);

                if (! $student->isAdult() && $student->guardians()->doesntExist()) {
                    throw new ImportRowException('El jugador es menor de edad: cargá al menos un tutor.');
                }

                Family::syncFor($student);
                $this->enroll($student, $group, $season, $status);

                return [$student, $toInvite];
            });

            foreach ($toInvite as $guardian) {
                $this->invite($guardian, $invitedBy);
            }

            return $student;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function student(array $data, bool $mustBeNew): Student
    {
        $birthDate = CarbonImmutable::parse($data['birth_date'])->toDateString();

        $student = Student::findExisting($data['document'] ?? null, $data['first_name'], $data['last_name'], $birthDate);

        if ($student !== null && $mustBeNew) {
            throw new ImportRowException('Ya está cargado: inscribilo desde su ficha.');
        }

        $student ??= new Student;

        $student->fill(array_filter([
            ...collect($data)->only(['first_name', 'last_name', 'document', 'shirt_size', 'position', 'notes', 'user_id'])->all(),
            'birth_date' => $birthDate,
        ], fn ($value) => $value !== null))->save();

        return $student;
    }

    /**
     * Crea o reutiliza los tutores y los vincula. Devuelve los que hay que invitar.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<Guardian>
     */
    private function guardians(Student $student, array $rows): array
    {
        $resolved = [];
        $toInvite = [];

        foreach ($rows as $data) {
            $data = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);

            if (blank($data['first_name'] ?? null)) {
                continue;
            }

            $email = filled($data['email'] ?? null) ? mb_strtolower($data['email']) : null;

            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new ImportRowException("Email de tutor inválido: {$email}.");
            }

            $familyId = $student->family_id ?? collect($resolved)->pluck('family_id')->filter()->first();

            $guardian = ($email ? Guardian::query()->where('email', $email)->first() : null)
                ?? (filled($data['document'] ?? null) ? Guardian::query()->where('document', $data['document'])->first() : null)
                ?? $this->sameNameInFamily($student, $data, $familyId)
                ?? new Guardian;

            $guardian->fill(array_filter([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? '',
                'document' => $data['document'] ?? null,
                'email' => $email,
                'phone' => $data['phone'] ?? null,
            ], fn ($value) => $value !== null))->save();

            $student->guardians()->syncWithoutDetaching([
                $guardian->id => ['relationship' => GuardianRelationship::parse($data['relationship'] ?? null)->value],
            ]);

            $resolved[] = $guardian;

            if ($data['invite'] ?? false) {
                $toInvite[] = $guardian;
            }
        }

        return $toInvite;
    }

    /**
     * Tutor sin correo ni documento: se reconoce por nombre entre los tutores del alumno o de su familia.
     *
     * @param  array<string, mixed>  $data
     */
    private function sameNameInFamily(Student $student, array $data, ?int $familyId): ?Guardian
    {
        return Guardian::query()
            ->where('first_name', $data['first_name'])
            ->where('last_name', $data['last_name'] ?? '')
            ->where(fn ($query) => $query
                ->whereHas('students', fn ($students) => $students->whereKey($student->id))
                ->when($familyId, fn ($query) => $query->orWhere('family_id', $familyId)))
            ->first();
    }

    private function enroll(Student $student, Group $group, Season $season, ?EnrollmentStatus $status): void
    {
        $enrollment = Enrollment::query()->firstOrNew([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'season_id' => $season->id,
        ]);

        if (! $enrollment->exists || $status !== null) {
            $enrollment->status = $status ?? EnrollmentStatus::Active;
            $enrollment->enrolled_on ??= now()->toDateString();
            $enrollment->save();
        }
    }

    /**
     * Invita al tutor con correo que todavía no usa la app ni tiene una invitación pendiente.
     */
    private function invite(Guardian $guardian, ?User $invitedBy): void
    {
        $pending = $guardian->invitations()
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->exists();

        if ($guardian->email !== null && ! $guardian->hasAccount() && ! $pending) {
            $this->invitations->forGuardian($guardian, $invitedBy);
            $this->invited++;
        }
    }
}
