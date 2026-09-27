<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MoneyAccount;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MoneyAccountPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MoneyAccount');
    }

    public function view(AuthUser $authUser, MoneyAccount $moneyAccount): bool
    {
        return $authUser->can('View:MoneyAccount');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MoneyAccount');
    }

    public function update(AuthUser $authUser, MoneyAccount $moneyAccount): bool
    {
        return $authUser->can('Update:MoneyAccount');
    }

    public function delete(AuthUser $authUser, MoneyAccount $moneyAccount): bool
    {
        return $authUser->can('Delete:MoneyAccount');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:MoneyAccount');
    }

    public function restore(AuthUser $authUser, MoneyAccount $moneyAccount): bool
    {
        return $authUser->can('Restore:MoneyAccount');
    }

    public function forceDelete(AuthUser $authUser, MoneyAccount $moneyAccount): bool
    {
        return $authUser->can('ForceDelete:MoneyAccount');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MoneyAccount');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MoneyAccount');
    }

    public function replicate(AuthUser $authUser, MoneyAccount $moneyAccount): bool
    {
        return $authUser->can('Replicate:MoneyAccount');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MoneyAccount');
    }
}
