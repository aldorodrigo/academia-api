<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    $this->ajena = Organization::factory()->create(['slug' => 'ajena']);
    $this->user = memberOf($this->jakare);
    $this->assigner = app(RoleAssigner::class);
});

function hasRoleIn(Organization $organization, $user, string $role): bool
{
    return app(CurrentOrganization::class)->run(
        $organization,
        fn () => $user->fresh()->hasRole($role),
    );
}

it('crea los roles base al crear una organización', function () {
    expect(Role::query()->where('organization_id', $this->jakare->id)->pluck('name')->sort()->values()->all())
        ->toEqual(collect(OrganizationRole::cases())->pluck('value')->sort()->values()->all());
});

it('asignar un rol lo sincroniza con spatie solo en esa organización', function () {
    $this->assigner->assign($this->jakare, $this->user, OrganizationRole::Guardian);

    expect(hasRoleIn($this->jakare, $this->user, 'tutor'))->toBeTrue()
        ->and(hasRoleIn($this->ajena, $this->user, 'tutor'))->toBeFalse();
});

it('un cargo de comisión exige fecha de fin del mandato', function () {
    $this->assigner->assign($this->jakare, $this->user, OrganizationRole::Treasurer);
})->throws(ValidationException::class);

it('el fin del mandato no puede ser anterior al inicio', function () {
    $this->assigner->assign(
        $this->jakare, $this->user, OrganizationRole::Treasurer,
        Carbon::parse('2027-01-01'), Carbon::parse('2026-01-01'),
    );
})->throws(ValidationException::class);

it('terminar una asignación quita el rol pero conserva el historial', function () {
    $assignment = $this->assigner->assign(
        $this->jakare, $this->user, OrganizationRole::Treasurer, null, now()->addYear(),
    );

    $this->assigner->end($assignment);

    expect(hasRoleIn($this->jakare, $this->user, 'tesorero'))->toBeFalse()
        ->and(RoleAssignment::withoutGlobalScopes()->whereKey($assignment->id)->value('ended_at'))->not->toBeNull();
});

it('roles:expire termina los mandatos vencidos según la fecha local', function () {
    // 23:30 del 31/12 en Asunción ya es 1/1 en UTC: el mandato todavía no venció.
    $this->travelTo(Carbon::parse('2027-01-01 02:30:00', 'UTC'));

    $vigente = $this->assigner->assign(
        $this->jakare, $this->user, OrganizationRole::Treasurer, null, Carbon::parse('2026-12-31'),
    );
    $vencido = $this->assigner->assign(
        $this->jakare, memberOf($this->jakare), OrganizationRole::Member, null, Carbon::parse('2026-12-30'),
    );

    $this->artisan('roles:expire')->assertSuccessful();

    expect($vigente->fresh()->ended_at)->toBeNull()
        ->and($vencido->fresh()->ended_at)->not->toBeNull()
        ->and(hasRoleIn($this->jakare, $this->user, 'tesorero'))->toBeTrue();
});

it('roles:expire activa los mandatos que empiezan hoy', function () {
    $this->travelTo(Carbon::parse('2026-12-31 12:00:00', 'America/Asuncion'));
    $this->assigner->assign(
        $this->jakare, $this->user, OrganizationRole::President,
        Carbon::parse('2027-01-01'), Carbon::parse('2028-12-31'),
    );
    expect(hasRoleIn($this->jakare, $this->user, 'presidente'))->toBeFalse();

    $this->travelTo(Carbon::parse('2027-01-01 00:05:00', 'America/Asuncion'));
    $this->artisan('roles:expire')->assertSuccessful();

    expect(hasRoleIn($this->jakare, $this->user, 'presidente'))->toBeTrue();
});

it('el admin de la organización tiene acceso total solo en su organización', function () {
    $this->assigner->assign($this->jakare, $this->user, OrganizationRole::Admin);

    $current = app(CurrentOrganization::class);

    expect($current->run($this->jakare, fn () => $this->user->can('ViewAny:Season')))->toBeTrue()
        ->and($current->run($this->ajena, fn () => $this->user->can('ViewAny:Season')))->toBeFalse();
});

it('users:super-admin otorga y quita el acceso', function () {
    $this->artisan('users:super-admin', ['email' => $this->user->email])->assertSuccessful();
    expect($this->user->fresh()->is_super_admin)->toBeTrue();

    $this->artisan('users:super-admin', ['email' => $this->user->email, '--revoke' => true])->assertSuccessful();
    expect($this->user->fresh()->is_super_admin)->toBeFalse();
});
