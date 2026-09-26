<?php

use App\Filament\Resources\Seasons\Pages\CreateSeason;
use App\Models\Organization;
use App\Models\Season;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

beforeEach(function () {
    $this->jakare = Organization::factory()->create(['name' => 'Jakare', 'slug' => 'jakare']);
    $this->ajena = Organization::factory()->create(['slug' => 'ajena']);
});

it('un miembro entra al panel de su organización', function () {
    $user = memberOf($this->jakare);

    $this->actingAs($user)->get('/admin/jakare')->assertOk();
});

it('el super admin entra al panel y a la gestión de roles', function () {
    $user = memberOf($this->jakare, ['is_super_admin' => true]);

    $this->actingAs($user)->get('/admin/jakare')->assertOk();
    $this->actingAs($user)->get('/admin/jakare/shield/roles')->assertOk();
});

it('un usuario no puede entrar al panel de una organización ajena', function () {
    $user = memberOf($this->jakare);

    $this->actingAs($user)->get('/admin/ajena')->assertNotFound();
});

it('un usuario sin organizaciones no accede al panel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('crear una temporada desde el panel la asigna a la organización activa', function () {
    $user = memberOf($this->jakare, ['is_super_admin' => true]);

    $this->actingAs($user);
    filament()->setTenant($this->jakare);
    app(CurrentOrganization::class)->set($this->jakare);

    Livewire\Livewire::test(CreateSeason::class)
        ->fillForm([
            'name' => '2027',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Season::withoutGlobalScopes()->where('name', '2027')->value('organization_id'))
        ->toBe($this->jakare->id);
});
