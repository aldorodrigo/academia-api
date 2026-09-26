<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invitation;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InvitationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Invitation');
    }

    public function view(AuthUser $authUser, Invitation $record): bool
    {
        return $authUser->can('View:Invitation');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Invitation');
    }

    public function update(AuthUser $authUser, Invitation $record): bool
    {
        return $authUser->can('Update:Invitation');
    }

    public function delete(AuthUser $authUser, Invitation $record): bool
    {
        return $authUser->can('Delete:Invitation');
    }
}
