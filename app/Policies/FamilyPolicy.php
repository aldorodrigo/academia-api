<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Family;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FamilyPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Family');
    }

    public function view(AuthUser $authUser, Family $family): bool
    {
        return $authUser->can('View:Family');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Family');
    }

    public function update(AuthUser $authUser, Family $family): bool
    {
        return $authUser->can('Update:Family');
    }

    public function delete(AuthUser $authUser, Family $family): bool
    {
        return $authUser->can('Delete:Family');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Family');
    }

    public function restore(AuthUser $authUser, Family $family): bool
    {
        return $authUser->can('Restore:Family');
    }

    public function forceDelete(AuthUser $authUser, Family $family): bool
    {
        return $authUser->can('ForceDelete:Family');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Family');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Family');
    }

    public function replicate(AuthUser $authUser, Family $family): bool
    {
        return $authUser->can('Replicate:Family');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Family');
    }
}
