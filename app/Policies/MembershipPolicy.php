<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Membership;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MembershipPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Membership');
    }

    public function view(AuthUser $authUser, Membership $record): bool
    {
        return $authUser->can('View:Membership');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Membership');
    }

    public function update(AuthUser $authUser, Membership $record): bool
    {
        return $authUser->can('Update:Membership');
    }

    public function delete(AuthUser $authUser, Membership $record): bool
    {
        return $authUser->can('Delete:Membership');
    }
}
