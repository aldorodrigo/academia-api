<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\CashDeposits\CashDepositResource;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Organization;
use App\Models\Role;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * Textos del panel (hallazgo N5): mayúscula solo al principio, roles con su nombre en español y
 * el vocabulario de la organización, sin la columna "Guard" y fechas locales.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 11:32:17', 'America/Asuncion'));
    $this->jakare = Organization::factory()->create(['slug' => 'jakare', 'terminology' => ['instructor' => 'Profe']]);
    $this->admin = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $this->admin, OrganizationRole::Admin);
    $this->actingAs($this->admin);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->jakare);
    app(CurrentOrganization::class)->set($this->jakare);
});

it('el menú y los títulos van con mayúscula solo al principio', function () {
    expect(CashDepositResource::getNavigationLabel())->toBe('Depósitos de efectivo')
        ->and(CashDepositResource::getTitleCaseModelLabel())->toBe('Depósito de efectivo');
});

it('los roles muestran su nombre en español, sin la columna Guard y con fechas locales', function () {
    $roles = Role::query()->whereIn('name', ['admin', 'sindico', 'instructor'])->get();
    expect($roles)->toHaveCount(3);

    Livewire::test(ListRoles::class)
        ->assertCanSeeTableRecords($roles)
        ->assertSee(['Administrador', 'Síndico', 'Profe'])
        ->assertDontSee('Sindico')
        ->assertTableColumnDoesNotExist('guard_name')
        ->assertSee('05/10/2026 11:32')
        ->assertDontSee('oct. 5, 2026');

    expect(RoleResource::getRecordTitle($roles->firstWhere('name', 'sindico')))->toBe('Síndico');

    // El nombre interno no cambia.
    Livewire::test(EditRole::class, ['record' => $roles->firstWhere('name', 'sindico')->getKey()])
        ->assertSchemaStateSet(['name' => 'sindico'])
        ->assertSee('Se muestra como «Síndico».');
});

it('los permisos personalizados y los recursos van con mayúscula solo al principio', function () {
    $custom = FilamentShield::getCustomPermissions(true);

    expect($custom)->toContain('Condonar deudas', 'Cobrar en efectivo desde la app', 'Confirmar inscripciones de la app (todas las categorías)')
        ->not->toContain('Condonar Deudas')
        ->and(FilamentShield::getLocalizedResourceLabel(CashDepositResource::class))->toBe('Depósito de efectivo');
});
