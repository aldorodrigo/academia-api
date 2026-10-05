<?php

namespace App\Actions\Enrollments;

use App\Actions\Students\RegisterStudent;
use App\Enums\EnrollmentStatus;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Exceptions\ImportRowException;
use App\Models\Enrollment;
use App\Models\EnrollmentRequest;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Organization;
use App\Models\Season;
use App\Models\Student;
use App\Models\User;
use App\Notifications\EnrollmentRequested;
use App\Support\Vocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Un miembro pide la inscripción de un hijo desde la app. "Entra ya, se confirma después": el chico queda
 * dado de alta con la inscripción `pendiente` (aparece en la lista del técnico y va a clases, sin cuotas) y
 * quienes confirman reciben el aviso. Si quien la pide puede confirmar en esa categoría, se confirma sola.
 */
class SubmitEnrollmentRequest
{
    private const MEDICAL_FIELDS = ['blood_type', 'allergies', 'conditions', 'medications', 'emergency_contact_name', 'emergency_contact_phone'];

    public function __construct(
        private RegisterStudent $register,
        private ReviewEnrollmentRequest $review,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, birth_date: string, document: string, relationship?: ?string, gender?: ?string, season_id: int, group_id: int, notes?: ?string, medical?: ?array<string, ?string>}  $data
     */
    public function handle(Organization $organization, User $user, array $data): EnrollmentRequest
    {
        [$season, $group] = EnrollmentRequestAccess::place((int) $data['season_id'], (int) $data['group_id']);

        $child = [
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'document' => trim($data['document']),
            'birth_date' => CarbonImmutable::parse($data['birth_date'])->toDateString(),
        ];

        $this->ensureNotRepeated($organization, $user, $child);
        // También los archivados (ej. una solicitud rechazada): vuelve el mismo alumno, con su historial.
        $existing = Student::findExisting($child['document'], $child['first_name'], $child['last_name'], $child['birth_date'], withTrashed: true);
        $wasArchived = $existing?->trashed() ?? false;
        $previous = $existing?->enrollments()->withTrashed()
            ->where('group_id', $group->id)->where('season_id', $season->id)
            ->orderByRaw('deleted_at IS NOT NULL')->first();
        $this->ensureNotEnrolled($user, $existing, $previous, $season, $group);

        $relationship = GuardianRelationship::parse($data['relationship'] ?? null);
        $gender = Gender::parse($data['gender'] ?? null);
        $medical = collect($data['medical'] ?? [])->only(self::MEDICAL_FIELDS)
            ->map(fn ($value) => filled($value) ? trim((string) $value) : null)
            ->filter()
            ->all();

        $request = DB::transaction(function () use ($organization, $user, $data, $season, $group, $child, $existing, $wasArchived, $previous, $relationship, $medical, $gender) {
            // Al chico de otra familia (ya cargado, con tutores) el tutor se le vincula recién al confirmar:
            // hasta entonces no ve sus datos.
            $linkNow = $existing === null
                || Student::query()->withTrashed()->inChargeOf($user)->whereKey($existing->id)->exists()
                || $existing->guardians()->doesntExist();

            try {
                $student = $this->register->handle(
                    $organization,
                    // El género (opcional) solo completa el que falta.
                    [...($existing ? self::studentData($existing) : $child), 'gender' => $existing?->gender ?? $gender],
                    $group,
                    $season,
                    EnrollmentStatus::Pending,
                    $linkNow ? [self::guardianRow($user, $relationship)] : [],
                    $user,
                );
            } catch (ImportRowException $exception) {
                throw ValidationException::withMessages(['document' => $exception->getMessage()]);
            }

            $enrollment = Enrollment::query()->where('student_id', $student->id)
                ->where('group_id', $group->id)->where('season_id', $season->id)->sole();

            // Vuelve después de una baja: se cuenta desde hoy (los meses que estuvo afuera no se cobran).
            if ($previous !== null) {
                $enrollment->update(['enrolled_on' => $organization->today()->toDateString()]);
            }

            return EnrollmentRequest::query()->create([
                ...$child,
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'relationship' => $relationship,
                'gender' => $gender,
                'season_id' => $season->id,
                'group_id' => $group->id,
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'medical' => $medical === [] ? null : $medical,
                'student_id' => $student->id,
                'enrollment_id' => $enrollment->id,
                // Uno restaurado cuenta como creado por la solicitud: al rechazarla se vuelve a archivar.
                'student_created' => $existing === null || $wasArchived,
                'previous_enrollment_status' => $previous !== null && ! $previous->trashed() ? $previous->status->value : null,
                'guardian_linked' => $linkNow,
            ]);
        });

        // Quien puede confirmar en esa categoría (admin, secretario, el técnico del grupo) no espera a nadie.
        if (EnrollmentRequestAccess::canReviewGroup($user, $group)) {
            return $this->review->approve($request, $user);
        }

        Notification::send(EnrollmentRequestAccess::reviewers($organization, $group), new EnrollmentRequested($request));

        return $request;
    }

    /**
     * El tutor que queda vinculado: su ficha de tutor si ya tiene una (sin pisar sus datos) o una nueva con su
     * nombre y sus datos verificados.
     *
     * @return array<string, mixed>
     */
    public static function guardianRow(User $user, GuardianRelationship $relationship): array
    {
        $guardian = Guardian::query()->where('user_id', $user->id)->first();
        [$firstName, $lastName] = $guardian
            ? [$guardian->first_name, $guardian->last_name]
            : array_pad(explode(' ', trim($user->name), 2), 2, '');

        return [
            'user_id' => $user->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $guardian ? null : ($user->email_verified_at ? $user->email : null),
            'phone' => $guardian ? null : ($user->phone_verified_at ? $user->phone : null),
            'relationship' => $relationship->value,
            'invite' => false,
        ];
    }

    /**
     * Los datos que ya tiene el alumno (así `RegisterStudent` no los cambia con lo que escribió el tutor).
     *
     * @return array<string, mixed>
     */
    public static function studentData(Student $student): array
    {
        return [
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'document' => $student->document,
            'birth_date' => $student->birth_date,
        ];
    }

    /**
     * Una sola solicitud por confirmar por documento.
     *
     * @param  array{first_name: string, last_name: string, document: string, birth_date: string}  $child
     */
    private function ensureNotRepeated(Organization $organization, User $user, array $child): void
    {
        $repeated = EnrollmentRequest::query()->pending()->where('document', $child['document'])->first();

        if ($repeated !== null) {
            throw ValidationException::withMessages(['document' => $repeated->user_id === $user->id
                ? "Ya mandaste una solicitud para {$child['first_name']}; esperá a que ".Vocabulary::the($organization->typeNoun()).' la confirme.'
                : 'Ya hay una solicitud por confirmar con ese documento.']);
        }
    }

    /**
     * Si ya es su hijo y está inscripto en esa disciplina y temporada, o el chico ya está en esa categoría, no
     * hay nada que pedir. Una baja de esa categoría sí se puede volver a pedir.
     */
    private function ensureNotEnrolled(User $user, ?Student $student, ?Enrollment $previous, Season $season, Group $group): void
    {
        if ($student === null) {
            return;
        }

        if ($previous !== null && ! $previous->trashed() && $previous->status !== EnrollmentStatus::Withdrawn) {
            throw ValidationException::withMessages([
                'document' => "Ese documento ya tiene inscripción en {$group->name} ({$season->name}). Consultá con ".Vocabulary::the($group->organization->typeNoun()).'.',
            ]);
        }

        if (! Student::query()->inChargeOf($user)->whereKey($student->id)->exists()) {
            return;
        }

        $enrolled = $student->enrollments()
            ->where('season_id', $season->id)
            ->whereHas('group', fn ($query) => $query->where('program_id', $group->program_id))
            ->where('status', '!=', EnrollmentStatus::Withdrawn)
            ->exists();

        if ($enrolled) {
            throw ValidationException::withMessages([
                'document' => "{$student->first_name} ya tiene inscripción en {$group->program->name} ({$season->name}).",
            ]);
        }
    }
}
