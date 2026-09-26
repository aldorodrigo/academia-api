<?php

use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->jakare = Organization::factory()->create(['name' => 'Jakare', 'slug' => 'jakare']);
    $this->ajena = Organization::factory()->create(['slug' => 'ajena']);
    $this->user = memberOf($this->jakare);
});

it('exige el header X-Organization', function () {
    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/organization')
        ->assertBadRequest();
});

it('devuelve la organización activa con su vocabulario', function () {
    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/organization', ['X-Organization' => 'jakare'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'jakare')
        ->assertJsonPath('data.currency', 'PYG')
        ->assertJsonPath('data.terminology.group', 'Categoría');
});

it('prohíbe entrar a una organización a la que no pertenece', function () {
    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/organization', ['X-Organization' => 'ajena'])
        ->assertForbidden();
});

it('prohíbe entrar con una membresía inactiva', function () {
    $this->jakare->memberships()->where('user_id', $this->user->id)->update(['status' => 'inactive']);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/organization', ['X-Organization' => 'jakare'])
        ->assertForbidden();
});

it('el super admin puede entrar a cualquier organización', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/organization', ['X-Organization' => 'ajena'])
        ->assertOk();
});

it('requiere autenticación', function () {
    $this->getJson('/api/v1/organization', ['X-Organization' => 'jakare'])
        ->assertUnauthorized();
});
