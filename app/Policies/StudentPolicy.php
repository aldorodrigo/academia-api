<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Student;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as AuthUser;

class StudentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Student');
    }

    public function view(AuthUser $authUser, Student $student): bool
    {
        return $authUser->can('View:Student');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Student');
    }

    public function update(AuthUser $authUser, Student $student): bool
    {
        return $authUser->can('Update:Student');
    }

    public function delete(AuthUser $authUser, Student $student): bool
    {
        return $authUser->can('Delete:Student');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Student');
    }

    public function restore(AuthUser $authUser, Student $student): bool
    {
        return $authUser->can('Restore:Student');
    }

    public function forceDelete(AuthUser $authUser, Student $student): bool
    {
        return $authUser->can('ForceDelete:Student');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Student');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Student');
    }

    public function replicate(AuthUser $authUser, Student $student): bool
    {
        return $authUser->can('Replicate:Student');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Student');
    }

    /**
     * Ficha médica: roles con el permiso, el tutor del alumno, el propio alumno
     * adulto y los instructores de sus grupos en la temporada actual.
     * El admin de la organización pasa por Gate::before.
     */
    public function viewMedical(AuthUser $authUser, Student $student): bool
    {
        if ($authUser->can('ViewMedical:Student') || $student->user_id === $authUser->getKey()) {
            return true;
        }

        return $student->guardians()->where('guardians.user_id', $authUser->getKey())->exists()
            || $student->currentEnrollments()
                ->whereHas('group.instructors', fn (Builder $query) => $query->whereKey($authUser->getKey()))
                ->exists();
    }
}
