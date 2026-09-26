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
use App\Models\Program;
use App\Models\Season;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Importa una fila de la planilla de alumnos (una fila por alumno, hasta dos tutores):
 * crea o actualiza el alumno, sus tutores, la familia y la inscripción en la temporada actual.
 *
 * Volver a importar la misma planilla no duplica nada: el alumno se busca por documento
 * (o nombre + fecha de nacimiento) y el tutor por email o documento.
 */
class ImportStudentRow
{
    public function __construct(
        private CurrentOrganization $current,
        private CreateInvitation $invitations,
    ) {}

    /**
     * @param  array{first_name: ?string, last_name: ?string, document?: ?string, birth_date: mixed, shirt_size?: ?string, position?: ?string, program: ?string, group: ?string, status?: ?string, guardians?: list<array{first_name?: ?string, last_name?: ?string, document?: ?string, email?: ?string, phone?: ?string, relationship?: ?string}>}  $row
     */
    public function handle(Organization $organization, array $row, bool $invite = false, ?User $invitedBy = null): Student
    {
        return $this->current->run($organization, function (Organization $organization) use ($row, $invite, $invitedBy) {
            $row = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $row);

            if (blank($row['first_name'] ?? null) || blank($row['last_name'] ?? null)) {
                throw new ImportRowException('Falta el nombre o el apellido.');
            }

            $birthDate = $this->date($row['birth_date'] ?? null);
            $season = Season::currentOrNull() ?? throw new ImportRowException('No hay una temporada actual.');
            $group = $this->group($organization, $row['program'] ?? null, $row['group'] ?? null);
            $status = $this->status($row['status'] ?? null);

            [$student, $guardians] = DB::transaction(function () use ($row, $birthDate, $group, $season, $status) {
                $student = $this->student($row, $birthDate);
                $guardians = $this->guardians($student, $row['guardians'] ?? []);
                $this->family($student, $guardians);

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

                return [$student, $guardians];
            });

            if ($invite) {
                foreach ($guardians as $guardian) {
                    $this->invite($guardian, $invitedBy);
                }
            }

            return $student;
        });
    }

    private function group(Organization $organization, ?string $programName, ?string $groupName): Group
    {
        if ($programName === null || $groupName === null) {
            throw new ImportRowException("Faltan {$organization->term('program')} o {$organization->term('group')}.");
        }

        $program = Program::query()->where('name', $programName)->first()
            ?? throw new ImportRowException("No existe {$organization->term('program')} \"{$programName}\".");

        return $program->groups()->where('name', $groupName)->first()
            ?? throw new ImportRowException("No existe {$organization->term('group')} \"{$groupName}\" en {$program->name}.");
    }

    private function status(?string $value): ?EnrollmentStatus
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower($value);

        return EnrollmentStatus::tryFrom($value)
            ?? collect(EnrollmentStatus::cases())->first(fn (EnrollmentStatus $s) => mb_strtolower($s->label()) === $value)
            ?? throw new ImportRowException("Estado \"{$value}\" inválido: usá pendiente, activo, becado, suspendido o baja.");
    }

    private function date(mixed $value): CarbonImmutable
    {
        try {
            return match (true) {
                $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->startOfDay(),
                is_string($value) && preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $value) === 1 => CarbonImmutable::createFromFormat('!d/m/Y', $value),
                is_string($value) && preg_match('#^\d{4}-\d{2}-\d{2}#', $value) === 1 => CarbonImmutable::parse(substr($value, 0, 10)),
                default => throw new ImportRowException('Fecha de nacimiento inválida: usá dd/mm/aaaa.'),
            };
        } catch (ImportRowException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ImportRowException('Fecha de nacimiento inválida: usá dd/mm/aaaa.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function student(array $row, CarbonImmutable $birthDate): Student
    {
        $student = filled($row['document'] ?? null)
            ? Student::query()->where('document', $row['document'])->first()
            : null;

        $student ??= Student::query()
            ->where('first_name', $row['first_name'])
            ->where('last_name', $row['last_name'])
            ->whereDate('birth_date', $birthDate)
            ->first();

        $student ??= new Student;

        $student->fill(array_filter([
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'document' => $row['document'] ?? null,
            'birth_date' => $birthDate->toDateString(),
            'shirt_size' => $row['shirt_size'] ?? null,
            'position' => $row['position'] ?? null,
        ], fn ($value) => $value !== null))->save();

        return $student;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<Guardian>
     */
    private function guardians(Student $student, array $rows): array
    {
        $guardians = [];

        foreach ($rows as $data) {
            $data = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);

            if (blank($data['first_name'] ?? null)) {
                continue;
            }

            $email = filled($data['email'] ?? null) ? mb_strtolower($data['email']) : null;

            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new ImportRowException("Email de tutor inválido: {$email}.");
            }

            $guardian = ($email ? Guardian::query()->where('email', $email)->first() : null)
                ?? (filled($data['document'] ?? null) ? Guardian::query()->where('document', $data['document'])->first() : null)
                ?? $this->sameNameInFamily($student, $data, $student->family_id ?? collect($guardians)->pluck('family_id')->filter()->first())
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

            $guardians[] = $guardian;
        }

        return $guardians;
    }

    /**
     * Tutor sin email ni documento: se reconoce por nombre entre los tutores del alumno o de su familia.
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

    /**
     * Hermanos: los que comparten tutor quedan en la misma familia.
     *
     * @param  list<Guardian>  $guardians
     */
    private function family(Student $student, array $guardians): void
    {
        $familyId = $student->family_id
            ?? collect($guardians)->pluck('family_id')->filter()->first()
            ?? ($guardians === [] ? null : Family::query()->create(['name' => "Familia {$student->last_name}"])->id);

        if ($familyId === null) {
            return;
        }

        $student->update(['family_id' => $familyId]);

        foreach ($guardians as $guardian) {
            if ($guardian->family_id === null) {
                $guardian->update(['family_id' => $familyId]);
            }
        }
    }

    /**
     * Invita al tutor con email que todavía no tiene cuenta ni una invitación pendiente.
     */
    private function invite(Guardian $guardian, ?User $invitedBy): void
    {
        $pending = $guardian->invitations()
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->exists();

        if ($guardian->email !== null && ! $guardian->hasAccount() && ! $pending) {
            $this->invitations->forGuardian($guardian, $invitedBy);
        }
    }
}
