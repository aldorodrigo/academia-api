<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Scholarship;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ScholarshipPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Scholarship');
    }

    public function view(AuthUser $authUser, Scholarship $scholarship): bool
    {
        return $authUser->can('View:Scholarship');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Scholarship');
    }

    public function update(AuthUser $authUser, Scholarship $scholarship): bool
    {
        return $authUser->can('Update:Scholarship');
    }

    public function delete(AuthUser $authUser, Scholarship $scholarship): bool
    {
        return $authUser->can('Delete:Scholarship');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Scholarship');
    }

    public function restore(AuthUser $authUser, Scholarship $scholarship): bool
    {
        return $authUser->can('Restore:Scholarship');
    }

    public function forceDelete(AuthUser $authUser, Scholarship $scholarship): bool
    {
        return $authUser->can('ForceDelete:Scholarship');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Scholarship');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Scholarship');
    }

    public function replicate(AuthUser $authUser, Scholarship $scholarship): bool
    {
        return $authUser->can('Replicate:Scholarship');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Scholarship');
    }

    /**
     * Aprobar, rechazar o revocar una beca (permiso custom de Shield).
     */
    public function approve(AuthUser $authUser, Scholarship $scholarship): bool
    {
        return $authUser->can('Approve:Scholarship');
    }
}
