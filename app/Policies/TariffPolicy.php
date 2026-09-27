<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tariff;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TariffPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Tariff');
    }

    public function view(AuthUser $authUser, Tariff $tariff): bool
    {
        return $authUser->can('View:Tariff');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Tariff');
    }

    public function update(AuthUser $authUser, Tariff $tariff): bool
    {
        return $authUser->can('Update:Tariff');
    }

    public function delete(AuthUser $authUser, Tariff $tariff): bool
    {
        return $authUser->can('Delete:Tariff');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Tariff');
    }

    public function restore(AuthUser $authUser, Tariff $tariff): bool
    {
        return $authUser->can('Restore:Tariff');
    }

    public function forceDelete(AuthUser $authUser, Tariff $tariff): bool
    {
        return $authUser->can('ForceDelete:Tariff');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Tariff');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Tariff');
    }

    public function replicate(AuthUser $authUser, Tariff $tariff): bool
    {
        return $authUser->can('Replicate:Tariff');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Tariff');
    }
}
