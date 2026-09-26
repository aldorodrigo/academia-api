<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Roles\RoleAssigner;
use Illuminate\Support\Carbon;

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

it('incluye los perfiles vigentes del usuario solo de la organización activa', function () {
    $assigner = app(RoleAssigner::class);
    $assigner->assign($this->jakare, $this->user, OrganizationRole::Guardian);
    $assigner->assign($this->jakare, $this->user, OrganizationRole::Treasurer, Carbon::parse('2026-01-01'), Carbon::parse('2099-12-31'));
    $vencido = $assigner->assign($this->jakare, $this->user, OrganizationRole::Member, null, Carbon::parse('2099-12-31'));
    $assigner->end($vencido);

    $this->ajena->memberships()->create(['user_id' => $this->user->id, 'status' => 'active']);
    $assigner->assign($this->ajena, $this->user, OrganizationRole::Admin);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/organization', ['X-Organization' => 'jakare'])
        ->assertOk()
        ->assertJsonPath('data.membership.roles', [
            ['name' => 'tutor', 'label' => 'Tutor', 'starts_on' => null, 'ends_on' => null],
            ['name' => 'tesorero', 'label' => 'Tesorero', 'starts_on' => '2026-01-01', 'ends_on' => '2099-12-31'],
        ]);
});
