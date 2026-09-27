<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Transfer;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TransferPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Transfer');
    }

    public function view(AuthUser $authUser, Transfer $transfer): bool
    {
        return $authUser->can('View:Transfer');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Transfer');
    }

    public function update(AuthUser $authUser, Transfer $transfer): bool
    {
        return $authUser->can('Update:Transfer');
    }

    public function delete(AuthUser $authUser, Transfer $transfer): bool
    {
        return $authUser->can('Delete:Transfer');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Transfer');
    }

    public function restore(AuthUser $authUser, Transfer $transfer): bool
    {
        return $authUser->can('Restore:Transfer');
    }

    public function forceDelete(AuthUser $authUser, Transfer $transfer): bool
    {
        return $authUser->can('ForceDelete:Transfer');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Transfer');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Transfer');
    }

    public function replicate(AuthUser $authUser, Transfer $transfer): bool
    {
        return $authUser->can('Replicate:Transfer');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Transfer');
    }
}
