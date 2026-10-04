<?php

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Enums\GuardianRelationship;
use App\Models\EnrollmentRequest;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Season;
use App\Models\Student;
use App\Models\User;
use App\Notifications\EnrollmentRequested;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Un miembro pide la inscripción de un hijo desde la app. No crea alumnos ni cargos: queda pendiente y se
 * avisa a quienes aprueban.
 */
class SubmitEnrollmentRequest
{
    private const MEDICAL_FIELDS = ['blood_type', 'allergies', 'conditions', 'medications', 'emergency_contact_name', 'emergency_contact_phone'];

    /**
     * @param  array{first_name: string, last_name: string, birth_date: string, document?: ?string, relationship?: ?string, season_id: int, group_id: int, notes?: ?string, medical?: ?array<string, ?string>}  $data
     */
    public function handle(Organization $organization, User $user, array $data): EnrollmentRequest
    {
        $season = Season::query()->open()->find($data['season_id']);
        if ($season === null) {
            throw ValidationException::withMessages(['season_id' => 'Elegí una temporada vigente o próxima.']);
        }

        $group = Group::query()->where('is_active', true)->with('program')->find($data['group_id']);
        if ($group === null || ! Season::query()->whereKey($season->id)->forProgram($group->program_id)->exists()) {
            throw ValidationException::withMessages(['group_id' => 'Elegí una categoría de la temporada.']);
        }

        $child = [
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'document' => filled($data['document'] ?? null) ? trim($data['document']) : null,
            'birth_date' => CarbonImmutable::parse($data['birth_date'])->toDateString(),
        ];

        $this->ensureNotRepeated($user, $child);
        $this->ensureNotEnrolled($user, $child, $season, $group);

        $medical = collect($data['medical'] ?? [])->only(self::MEDICAL_FIELDS)
            ->map(fn ($value) => filled($value) ? trim((string) $value) : null)
            ->filter()
            ->all();

        $request = EnrollmentRequest::query()->create([
            ...$child,
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'relationship' => GuardianRelationship::parse($data['relationship'] ?? null),
            'season_id' => $season->id,
            'group_id' => $group->id,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'medical' => $medical === [] ? null : $medical,
        ]);

        Notification::send(EnrollmentRequestAccess::reviewers($organization), new EnrollmentRequested($request));

        return $request;
    }

    /**
     * Una sola pendiente por chico (documento, o nombre + apellido + nacimiento) y usuario.
     *
     * @param  array{first_name: string, last_name: string, document: ?string, birth_date: string}  $child
     */
    private function ensureNotRepeated(User $user, array $child): void
    {
        $repeated = EnrollmentRequest::query()->pending()->where('user_id', $user->id)
            ->where(fn ($query) => $query
                ->when($child['document'], fn ($query, $document) => $query->where('document', $document))
                ->orWhere(fn ($query) => $query
                    ->where('first_name', $child['first_name'])
                    ->where('last_name', $child['last_name'])
                    ->whereDate('birth_date', $child['birth_date'])))
            ->exists();

        if ($repeated) {
            throw ValidationException::withMessages([
                'first_name' => "Ya mandaste una solicitud para {$child['first_name']}; esperá a que el club la revise.",
            ]);
        }
    }

    /**
     * Si ya es su hijo y está inscripto en esa disciplina y temporada, no hay nada que pedir.
     *
     * @param  array{first_name: string, last_name: string, document: ?string, birth_date: string}  $child
     */
    private function ensureNotEnrolled(User $user, array $child, Season $season, Group $group): void
    {
        $student = Student::findExisting($child['document'], $child['first_name'], $child['last_name'], $child['birth_date']);

        if ($student === null || ! Student::query()->inChargeOf($user)->whereKey($student->id)->exists()) {
            return;
        }

        $enrolled = $student->enrollments()
            ->where('season_id', $season->id)
            ->whereHas('group', fn ($query) => $query->where('program_id', $group->program_id))
            ->where('status', '!=', EnrollmentStatus::Withdrawn)
            ->exists();

        if ($enrolled) {
            throw ValidationException::withMessages([
                'first_name' => "{$student->first_name} ya tiene inscripción en {$group->program->name} ({$season->name}).",
            ]);
        }
    }
}
