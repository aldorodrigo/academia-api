<?php

namespace App\Actions\Students;

use App\Enums\EnrollmentStatus;
use App\Exceptions\ImportRowException;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Season;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Adapta una fila de la planilla de alumnos (una fila por alumno, hasta dos tutores)
 * y la registra con RegisterStudent en la temporada vigente de la disciplina (o la de la
 * columna `temporada`, obligatoria si hay varias vigentes).
 *
 * Si la categoría viene vacía, se usa la que corresponde por año de nacimiento.
 */
class ImportStudentRow
{
    public function __construct(
        private CurrentOrganization $current,
        private RegisterStudent $register,
    ) {}

    /**
     * @param  array{first_name: ?string, last_name: ?string, document?: ?string, birth_date: mixed, shirt_size?: ?string, position?: ?string, program?: ?string, group?: ?string, season?: ?string, status?: ?string, guardians?: list<array{first_name?: ?string, last_name?: ?string, document?: ?string, email?: ?string, phone?: ?string, relationship?: ?string}>}  $row
     */
    public function handle(Organization $organization, array $row, bool $invite = false, ?User $invitedBy = null): Student
    {
        return $this->current->run($organization, function (Organization $organization) use ($row, $invite, $invitedBy) {
            $row = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $row);

            if (blank($row['first_name'] ?? null) || blank($row['last_name'] ?? null)) {
                throw new ImportRowException('Falta el nombre o el apellido.');
            }

            $birthDate = $this->date($row['birth_date'] ?? null);
            $program = $this->program($organization, $row['program'] ?? null);
            $season = $this->season($row['season'] ?? null, $program);
            $group = $this->group($organization, $row['program'] ?? null, $row['group'] ?? null, $birthDate, $season);

            // Sin disciplina en la fila: la temporada tiene que ser de la disciplina de la categoría.
            if ($program === null && $season->programs()->exists() && ! $season->programs()->whereKey($group->program_id)->exists()) {
                $season = $this->season($row['season'] ?? null, $group->program);
            }

            return $this->register->handle(
                $organization,
                [
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'document' => $row['document'] ?? null,
                    'birth_date' => $birthDate,
                    'shirt_size' => $row['shirt_size'] ?? null,
                    'position' => $row['position'] ?? null,
                ],
                $group,
                $season,
                $this->status($row['status'] ?? null),
                collect($row['guardians'] ?? [])->map(fn (array $guardian) => [...$guardian, 'invite' => $invite])->all(),
                $invitedBy,
            );
        });
    }

    private function program(Organization $organization, ?string $name): ?Program
    {
        if ($name === null) {
            return null;
        }

        return Program::query()->where('name', $name)->first()
            ?? throw new ImportRowException("No existe {$organization->term('program')} \"{$name}\".");
    }

    /**
     * La de la columna `temporada` o, si no viene, la única vigente (o próxima) de la disciplina.
     */
    private function season(?string $name, ?Program $program): Season
    {
        $seasons = Season::query()
            ->open()
            ->when($program, fn ($query) => $query->forProgram($program))
            ->when($name, fn ($query) => $query->where('name', $name))
            ->get();

        if ($name !== null && $seasons->isEmpty()) {
            throw new ImportRowException("No existe la temporada \"{$name}\" (vigente o próxima).");
        }

        $active = $seasons->filter(fn (Season $season) => $season->status()->value === 'vigente');
        $candidates = $active->isNotEmpty() ? $active : $seasons;

        return match ($candidates->count()) {
            1 => $candidates->first(),
            0 => throw new ImportRowException('No hay una temporada vigente o próxima.'),
            default => throw new ImportRowException('Hay varias temporadas vigentes: indicá la temporada.'),
        };
    }

    private function group(Organization $organization, ?string $programName, ?string $groupName, CarbonImmutable $birthDate, Season $season): Group
    {
        if ($groupName === null) {
            return Group::suggestFor($birthDate, $season, $programName)
                ?? throw new ImportRowException("Falta {$organization->term('group')} y no hay una que corresponda por edad.");
        }

        $programs = Program::query()->when($programName, fn ($query) => $query->where('name', $programName))->get();

        if ($programName !== null && $programs->isEmpty()) {
            throw new ImportRowException("No existe {$organization->term('program')} \"{$programName}\".");
        }

        $groups = Group::query()->whereIn('program_id', $programs->modelKeys())->where('name', $groupName)->get();

        return match ($groups->count()) {
            1 => $groups->first(),
            0 => throw new ImportRowException($programName !== null
                ? "No existe {$organization->term('group')} \"{$groupName}\" en {$programs->first()->name}."
                : "No existe {$organization->term('group')} \"{$groupName}\"."),
            default => throw new ImportRowException("Hay varias {$organization->term('group')} \"{$groupName}\": indicá {$organization->term('program')}."),
        };
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
}
