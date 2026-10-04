<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LessonProfile;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class LessonProfilePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:LessonProfile');
    }

    public function view(AuthUser $authUser, LessonProfile $lessonProfile): bool
    {
        return $authUser->can('View:LessonProfile');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:LessonProfile');
    }

    public function update(AuthUser $authUser, LessonProfile $lessonProfile): bool
    {
        return $authUser->can('Update:LessonProfile');
    }

    public function delete(AuthUser $authUser, LessonProfile $lessonProfile): bool
    {
        return $authUser->can('Delete:LessonProfile');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:LessonProfile');
    }

    public function restore(AuthUser $authUser, LessonProfile $lessonProfile): bool
    {
        return $authUser->can('Restore:LessonProfile');
    }

    public function forceDelete(AuthUser $authUser, LessonProfile $lessonProfile): bool
    {
        return $authUser->can('ForceDelete:LessonProfile');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:LessonProfile');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:LessonProfile');
    }

    public function replicate(AuthUser $authUser, LessonProfile $lessonProfile): bool
    {
        return $authUser->can('Replicate:LessonProfile');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:LessonProfile');
    }
}
