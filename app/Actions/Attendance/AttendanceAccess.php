<?php

namespace App\Actions\Attendance;

use App\Models\ClassSession;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué grupos puede ver y tomar asistencia un usuario desde la app: los que
 * dirige como instructor, o todos con el permiso "Tomar asistencia".
 */
class AttendanceAccess
{
    public const PERMISSION = 'Take:Attendance';

    /**
     * @return Builder<Group>
     */
    public static function groups(User $user): Builder
    {
        return Group::query()
            ->unless($user->can(self::PERMISSION), fn (Builder $query) => $query
                ->whereHas('instructors', fn (Builder $instructors) => $instructors->whereKey($user->id)));
    }

    public static function canTakeAny(User $user): bool
    {
        return $user->can(self::PERMISSION) || $user->instructedGroups()->exists();
    }

    public static function allows(User $user, ClassSession $session): bool
    {
        return self::groups($user)->whereKey($session->group_id)->exists();
    }
}
