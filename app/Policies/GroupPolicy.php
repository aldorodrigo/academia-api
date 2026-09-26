<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Group;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class GroupPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Group');
    }

    public function view(AuthUser $authUser, Group $group): bool
    {
        return $authUser->can('View:Group');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Group');
    }

    public function update(AuthUser $authUser, Group $group): bool
    {
        return $authUser->can('Update:Group');
    }

    public function delete(AuthUser $authUser, Group $group): bool
    {
        return $authUser->can('Delete:Group');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Group');
    }

    public function restore(AuthUser $authUser, Group $group): bool
    {
        return $authUser->can('Restore:Group');
    }

    public function forceDelete(AuthUser $authUser, Group $group): bool
    {
        return $authUser->can('ForceDelete:Group');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Group');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Group');
    }

    public function replicate(AuthUser $authUser, Group $group): bool
    {
        return $authUser->can('Replicate:Group');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Group');
    }
}
