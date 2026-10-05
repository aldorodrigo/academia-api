<?php

namespace App\Support\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Season;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Alumnos dados de baja de la organización activa, para marcarlos en Saldos y Morosos.
 *
 * Un alumno está dado de baja cuando no tiene ninguna inscripción sin baja en temporadas vigentes o
 * próximas y tiene al menos una baja; la fecha es la de su última baja. Si sigue en otra disciplina,
 * no está dado de baja. Se carga una vez por informe (dos consultas).
 */
final class Withdrawals
{
    /**
     * @param  Collection<int, string>  $dates  fecha de baja (Y-m-d) por alumno
     */
    private function __construct(private Collection $dates) {}

    public static function load(): self
    {
        $open = Season::query()->open()->pluck('id')->all();

        $enrollments = Enrollment::query()
            ->get(['student_id', 'season_id', 'status', 'ended_on'])
            ->groupBy('student_id');

        $dates = $enrollments
            ->reject(fn (Collection $items) => $items->contains(
                fn (Enrollment $e) => $e->status !== EnrollmentStatus::Withdrawn && in_array($e->season_id, $open, true),
            ))
            ->map(fn (Collection $items) => $items
                ->filter(fn (Enrollment $e) => $e->status === EnrollmentStatus::Withdrawn)
                ->max(fn (Enrollment $e) => $e->ended_on?->toDateString() ?? ''))
            ->filter(fn (?string $date) => $date !== null);

        return new self($dates);
    }

    public function has(Student $student): bool
    {
        return $this->dates->has($student->id);
    }

    /**
     * Los dados de baja de un grupo de alumnos (una familia).
     *
     * @param  iterable<Student>  $students
     * @return list<array{student_id: int, student: string, on: ?string}>
     */
    public function for(iterable $students): array
    {
        return collect($students)
            ->filter(fn (Student $student) => $this->has($student))
            ->map(fn (Student $student) => [
                'student_id' => $student->id,
                'student' => $student->first_name,
                'on' => $this->dates->get($student->id) ?: null,
            ])
            ->values()
            ->all();
    }

    /**
     * Para PDF y Excel: "Matías (03/06/2026)".
     *
     * @param  list<array{student_id: int, student: string, on: ?string}>  $withdrawn
     */
    public static function describe(array $withdrawn): string
    {
        return collect($withdrawn)
            ->map(fn (array $w) => $w['student'].($w['on'] ? ' ('.date('d/m/Y', strtotime($w['on'])).')' : ''))
            ->join(', ');
    }
}
