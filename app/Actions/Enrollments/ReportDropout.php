<?php

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Enums\MembershipStatus;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\DropoutReported;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Dejó de venir": el técnico avisa desde la app que un alumno de su grupo no viene más. No da la
 * baja: la inscripción queda marcada y les llega un aviso (push y correo) a quienes pueden darla
 * (permiso de editar inscripciones o admin), que deciden "Dar de baja" o "Sigue viniendo".
 */
class ReportDropout
{
    /**
     * Inscripción activa o becada del alumno en el grupo (temporada vigente), o null.
     */
    public static function enrollmentFor(Group $group, int $studentId): ?Enrollment
    {
        return Enrollment::query()
            ->where('group_id', $group->id)
            ->where('student_id', $studentId)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Scholarship])
            ->whereHas('season', fn (Builder $season) => $season->active())
            ->latest('id')
            ->first();
    }

    public function report(Enrollment $enrollment, ?string $note, User $by): Enrollment
    {
        $first = ! $enrollment->hasDropoutReport();

        $enrollment->update([
            'dropout_reported_at' => $first ? now() : $enrollment->dropout_reported_at,
            'dropout_reported_by' => $by->id,
            'dropout_note' => filled($note) ? trim($note) : null,
        ]);

        activity('academic')->performedOn($enrollment)->causedBy($by)
            ->withProperties(['student_id' => $enrollment->student_id, 'note' => $enrollment->dropout_note])
            ->log('Aviso: dejó de venir');

        if ($first) {
            $notification = new DropoutReported($enrollment, $by);
            self::recipients($enrollment->organization)
                ->reject(fn (User $user) => $user->is($by))
                ->each(fn (User $user) => $user->notify($notification));
        }

        return $enrollment;
    }

    /**
     * El técnico lo deshace, o quien decide la baja lo descarta ("Sigue viniendo").
     */
    public function clear(Enrollment $enrollment, User $by): Enrollment
    {
        if (! $enrollment->hasDropoutReport()) {
            return $enrollment;
        }

        $enrollment->update(['dropout_reported_at' => null, 'dropout_reported_by' => null, 'dropout_note' => null]);

        activity('academic')->performedOn($enrollment)->causedBy($by)
            ->withProperties(['student_id' => $enrollment->student_id])
            ->log('Aviso descartado: sigue viniendo');

        return $enrollment;
    }

    /**
     * Miembros activos que pueden dar la baja.
     *
     * @return Collection<int, User>
     */
    public static function recipients(Organization $organization): Collection
    {
        return app(CurrentOrganization::class)->run($organization, fn () => $organization->users()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->get()
            ->filter(fn (User $user) => $user->can('Update:Enrollment'))
            ->values());
    }
}
