<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ClassPack;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ClassPackPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ClassPack');
    }

    public function view(AuthUser $authUser, ClassPack $classPack): bool
    {
        return $authUser->can('View:ClassPack');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ClassPack');
    }

    public function update(AuthUser $authUser, ClassPack $classPack): bool
    {
        return $authUser->can('Update:ClassPack');
    }

    public function delete(AuthUser $authUser, ClassPack $classPack): bool
    {
        return $authUser->can('Delete:ClassPack');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ClassPack');
    }

    public function restore(AuthUser $authUser, ClassPack $classPack): bool
    {
        return $authUser->can('Restore:ClassPack');
    }

    public function forceDelete(AuthUser $authUser, ClassPack $classPack): bool
    {
        return $authUser->can('ForceDelete:ClassPack');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ClassPack');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ClassPack');
    }

    public function replicate(AuthUser $authUser, ClassPack $classPack): bool
    {
        return $authUser->can('Replicate:ClassPack');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ClassPack');
    }
}
