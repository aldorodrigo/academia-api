<?php

use App\Actions\Roles\DeleteRole;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\Organization;
use App\Models\Role;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

/**
 * Roles del panel (hallazgo N9): los base no se borran; uno creado se borra solo si nadie lo tiene, se archiva y queda
 * registrado quién lo borró con la foto del rol.
 */
beforeEach(function () {
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    $this->admin = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $this->admin, OrganizationRole::Admin);
    $this->actingAs($this->admin);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->jakare);
    app(CurrentOrganization::class)->set($this->jakare);

    $this->custom = Role::query()->create(['name' => 'Utilero', 'guard_name' => 'web', 'organization_id' => $this->jakare->id]);
    $this->custom->givePermissionTo(Permission::findOrCreate('ViewAny:Student', 'web'));
});

it('un rol base no se puede borrar ni muestra "Borrar"', function () {
    $treasurer = Role::query()->where('name', 'tesorero')->firstOrFail();

    Livewire::test(ListRoles::class)
        ->assertActionHidden(TestAction::make(DeleteAction::class)->table($treasurer));
    Livewire::test(EditRole::class, ['record' => $treasurer->getKey()])
        ->assertActionHidden(DeleteAction::class);

    expect(fn () => app(DeleteRole::class)->handle($treasurer, $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $treasurer->delete())->toThrow(ValidationException::class)
        ->and($treasurer->fresh()->trashed())->toBeFalse();
});

it('un rol creado que alguien tiene no se puede borrar', function () {
    app(RoleAssigner::class)->assign($this->jakare, memberOf($this->jakare), $this->custom);

    Livewire::test(ListRoles::class)
        ->assertActionHidden(TestAction::make(DeleteAction::class)->table($this->custom));

    expect(fn () => app(DeleteRole::class)->handle($this->custom, $this->admin))
        ->toThrow(ValidationException::class, 'Este rol lo tiene alguien');
});

it('un rol creado sin uso se archiva y queda quién lo borró con su nombre y permisos', function () {
    Livewire::test(ListRoles::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($this->custom))
        ->assertHasNoActionErrors();

    expect(Role::query()->find($this->custom->id))->toBeNull()
        ->and(Role::withTrashed()->find($this->custom->id)->trashed())->toBeTrue();

    $log = Activity::query()->where('log_name', 'roles')->latest('id')->first();
    expect($log->description)->toBe('Rol borrado')
        ->and($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['name'])->toBe('Utilero')
        ->and($log->properties['permissions'])->toBe(['ViewAny:Student']);

    // Se puede volver a crear uno con el mismo nombre.
    Role::query()->create(['name' => 'Utilero', 'guard_name' => 'web', 'organization_id' => $this->jakare->id]);
    expect(Role::query()->where('name', 'Utilero')->count())->toBe(1);
});

it('el administrador dice "Todos" en Permisos y al editarlo se aclara', function () {
    Livewire::test(ListRoles::class)->assertSee('Todos');
    Livewire::test(EditRole::class, ['record' => Role::query()->where('name', 'admin')->value('id')])
        ->assertSee('El administrador puede hacer todo');
});

it('"Crear rol" con mayúscula solo al principio', function () {
    Livewire::test(ListRoles::class)->assertSee('Crear rol')->assertDontSee('Crear Rol');
    Livewire::test(CreateRole::class)->assertSee('Crear rol')->assertDontSee('Crear Rol');
});
