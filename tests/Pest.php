<?php

use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Crea un usuario con membresía activa en la organización dada.
 */
function memberOf(Organization $organization, array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    $organization->memberships()->create([
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    return $user;
}
