<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enrollment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class EnrollmentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Enrollment');
    }

    public function view(AuthUser $authUser, Enrollment $enrollment): bool
    {
        return $authUser->can('View:Enrollment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Enrollment');
    }

    public function update(AuthUser $authUser, Enrollment $enrollment): bool
    {
        return $authUser->can('Update:Enrollment');
    }

    public function delete(AuthUser $authUser, Enrollment $enrollment): bool
    {
        return $authUser->can('Delete:Enrollment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Enrollment');
    }

    public function restore(AuthUser $authUser, Enrollment $enrollment): bool
    {
        return $authUser->can('Restore:Enrollment');
    }

    public function forceDelete(AuthUser $authUser, Enrollment $enrollment): bool
    {
        return $authUser->can('ForceDelete:Enrollment');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Enrollment');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Enrollment');
    }

    public function replicate(AuthUser $authUser, Enrollment $enrollment): bool
    {
        return $authUser->can('Replicate:Enrollment');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Enrollment');
    }
}
