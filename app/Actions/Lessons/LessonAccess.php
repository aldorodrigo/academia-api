<?php

namespace App\Actions\Lessons;

use App\Enums\Feature;
use App\Models\Booking;
use App\Models\ClassPack;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quién puede qué en las clases particulares.
 */
class LessonAccess
{
    public static function ensureEnabled(Organization $organization): void
    {
        abort_unless($organization->hasFeature(Feature::PrivateLessons), 404, 'Esta organización no tiene clases particulares.');
    }

    /**
     * El alumno tiene reservas o paquetes con el profesor (así el profesor le puede cobrar y vender).
     */
    public static function isStudentOf(User $teacher, Student $student): bool
    {
        return Booking::query()->where('user_id', $teacher->id)->where('student_id', $student->id)->exists()
            || ClassPack::query()->where('user_id', $teacher->id)->where('student_id', $student->id)->exists();
    }

    /**
     * Usuarios a cargo del alumno (él mismo si es adulto y sus tutores): reciben los avisos.
     *
     * @return Collection<int, User>
     */
    public static function recipients(Student $student): Collection
    {
        $student->loadMissing('guardians');
        $ids = collect([$student->user_id])
            ->merge($student->guardians->pluck('user_id'))
            ->filter()
            ->unique();

        return User::query()->whereIn('id', $ids)->get();
    }
}
