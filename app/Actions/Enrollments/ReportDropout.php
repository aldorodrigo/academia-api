<?php

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Enums\MembershipStatus;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use App\Notifications\DropoutReported;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Vocabulary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Aviso de baja: el técnico avisa desde la app que un alumno de su grupo "dejó de venir", o el tutor
 * que su hijo "deja el club". No da la baja: la inscripción queda marcada y les llega un aviso (push
 * y correo) a quienes pueden darla (permiso de editar inscripciones o admin), que deciden "Dar de
 * baja" o "Sigue viniendo".
 */
class ReportDropout
{
    public const INSTRUCTOR = 'instructor';

    public const GUARDIAN = 'guardian';

    /**
     * Inscripciones que el tutor puede avisar: activas o becadas de temporadas vigentes o próximas.
     *
     * @return Collection<int, Enrollment>
     */
    public static function leavingEnrollments(Student $student): Collection
    {
        return Enrollment::query()
            ->where('student_id', $student->id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Scholarship])
            ->whereHas('season', fn (Builder $season) => $season->open())
            ->get();
    }

    /**
     * El tutor avisa que su hijo deja el club (todas sus inscripciones vigentes). Un solo aviso.
     */
    public function reportLeaving(Student $student, ?string $message, User $by): ?Enrollment
    {
        $enrollments = self::leavingEnrollments($student);
        $first = $enrollments->every(fn (Enrollment $e) => ! $e->hasDropoutReport());

        $enrollments->each(fn (Enrollment $enrollment) => $this->mark($enrollment, $message, $by, self::GUARDIAN));

        if ($first && $enrollments->isNotEmpty()) {
            $this->notifyDeciders($enrollments->first(), $by);
        }

        return $enrollments->first();
    }

    /**
     * El tutor deshace su aviso.
     */
    public function cancelLeaving(Student $student, User $by): void
    {
        self::leavingEnrollments($student)
            ->filter(fn (Enrollment $e) => $e->dropout_source === self::GUARDIAN)
            ->each(fn (Enrollment $enrollment) => $this->clear($enrollment, $by));
    }

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

    /**
     * El técnico avisa que dejó de venir.
     */
    public function report(Enrollment $enrollment, ?string $note, User $by): Enrollment
    {
        $first = ! $enrollment->hasDropoutReport();

        $this->mark($enrollment, $note, $by, self::INSTRUCTOR);

        if ($first) {
            $this->notifyDeciders($enrollment, $by);
        }

        return $enrollment;
    }

    private function mark(Enrollment $enrollment, ?string $note, User $by, string $source): void
    {
        $enrollment->update([
            'dropout_reported_at' => $enrollment->dropout_reported_at ?? now(),
            'dropout_reported_by' => $by->id,
            'dropout_note' => filled($note) ? trim($note) : null,
            'dropout_source' => $source,
        ]);

        activity('academic')->performedOn($enrollment)->causedBy($by)
            ->withProperties(['student_id' => $enrollment->student_id, 'note' => $enrollment->dropout_note, 'source' => $source])
            ->log($source === self::GUARDIAN ? 'Aviso: deja '.Vocabulary::the($enrollment->organization->typeNoun()) : 'Aviso: dejó de venir');
    }

    private function notifyDeciders(Enrollment $enrollment, User $by): void
    {
        $notification = new DropoutReported($enrollment, $by);
        self::recipients($enrollment->organization)
            ->reject(fn (User $user) => $user->is($by))
            ->each(fn (User $user) => $user->notify($notification));
    }

    /**
     * El técnico lo deshace, o quien decide la baja lo descarta ("Sigue viniendo").
     */
    public function clear(Enrollment $enrollment, User $by): Enrollment
    {
        if (! $enrollment->hasDropoutReport()) {
            return $enrollment;
        }

        $enrollment->update(['dropout_reported_at' => null, 'dropout_reported_by' => null, 'dropout_note' => null, 'dropout_source' => null]);

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
