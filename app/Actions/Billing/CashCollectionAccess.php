<?php

namespace App\Actions\Billing;

use App\Actions\Attendance\AttendanceAccess;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quién cobra en efectivo desde la app y a quién: el permiso "Cobrar en efectivo desde la
 * app" (técnico, tesorero y protesorero por defecto; el admin por Gate::before). Quien ve
 * todos los alumnos les cobra a todos; el resto, a los inscriptos en sus grupos.
 */
class CashCollectionAccess
{
    public const PERMISSION = 'Collect:Payments';

    public static function canCollect(User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    /**
     * Alumnos a los que puede cobrar (inscripción en una temporada vigente o próxima).
     *
     * @return Builder<Student>
     */
    public static function students(User $user): Builder
    {
        if ($user->can('ViewAny:Student')) {
            return Student::query();
        }

        $groups = AttendanceAccess::groups($user)->select('groups.id');

        return Student::query()->whereHas('currentEnrollments', fn (Builder $query) => $query->whereIn('group_id', $groups));
    }
}
