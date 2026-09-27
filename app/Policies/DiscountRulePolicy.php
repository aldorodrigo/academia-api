<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DiscountRule;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DiscountRulePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DiscountRule');
    }

    public function view(AuthUser $authUser, DiscountRule $discountRule): bool
    {
        return $authUser->can('View:DiscountRule');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DiscountRule');
    }

    public function update(AuthUser $authUser, DiscountRule $discountRule): bool
    {
        return $authUser->can('Update:DiscountRule');
    }

    public function delete(AuthUser $authUser, DiscountRule $discountRule): bool
    {
        return $authUser->can('Delete:DiscountRule');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DiscountRule');
    }

    public function restore(AuthUser $authUser, DiscountRule $discountRule): bool
    {
        return $authUser->can('Restore:DiscountRule');
    }

    public function forceDelete(AuthUser $authUser, DiscountRule $discountRule): bool
    {
        return $authUser->can('ForceDelete:DiscountRule');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DiscountRule');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DiscountRule');
    }

    public function replicate(AuthUser $authUser, DiscountRule $discountRule): bool
    {
        return $authUser->can('Replicate:DiscountRule');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DiscountRule');
    }
}
