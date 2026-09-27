<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Guardian;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class GuardianPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Guardian');
    }

    public function view(AuthUser $authUser, Guardian $guardian): bool
    {
        return $authUser->can('View:Guardian');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Guardian');
    }

    public function update(AuthUser $authUser, Guardian $guardian): bool
    {
        return $authUser->can('Update:Guardian');
    }

    public function delete(AuthUser $authUser, Guardian $guardian): bool
    {
        return $authUser->can('Delete:Guardian');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Guardian');
    }

    public function restore(AuthUser $authUser, Guardian $guardian): bool
    {
        return $authUser->can('Restore:Guardian');
    }

    public function forceDelete(AuthUser $authUser, Guardian $guardian): bool
    {
        return $authUser->can('ForceDelete:Guardian');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Guardian');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Guardian');
    }

    public function replicate(AuthUser $authUser, Guardian $guardian): bool
    {
        return $authUser->can('Replicate:Guardian');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Guardian');
    }
}
