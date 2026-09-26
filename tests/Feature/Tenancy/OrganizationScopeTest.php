<?php

use App\Models\Organization;
use App\Models\Season;
use App\Support\Tenancy\CurrentOrganization;

beforeEach(function () {
    $this->jakare = Organization::factory()->create(['name' => 'Jakare', 'slug' => 'jakare']);
    $this->otro = Organization::factory()->create(['name' => 'Otro Club', 'slug' => 'otro-club']);

    Season::factory()->for($this->jakare)->create(['name' => '2026']);
    Season::factory()->for($this->otro)->create(['name' => '2025']);
    Season::factory()->for($this->otro)->create(['name' => '2026']);
});

it('solo devuelve datos de la organización activa', function () {
    app(CurrentOrganization::class)->set($this->jakare);

    expect(Season::pluck('name')->all())->toBe(['2026'])
        ->and(Season::count())->toBe(1);
});

it('no permite leer registros de otra organización por id', function () {
    $ajena = Season::withoutGlobalScopes()->where('organization_id', $this->otro->id)->first();

    app(CurrentOrganization::class)->set($this->jakare);

    expect(Season::find($ajena->id))->toBeNull();
});

it('asigna la organización activa al crear', function () {
    app(CurrentOrganization::class)->set($this->jakare);

    $season = Season::create(['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);

    expect($season->organization_id)->toBe($this->jakare->id);
});

it('run() activa la organización y restaura la anterior', function () {
    $current = app(CurrentOrganization::class);
    $current->set($this->jakare);

    $count = $current->run($this->otro, fn () => Season::count());

    expect($count)->toBe(2)
        ->and($current->id())->toBe($this->jakare->id);
});

it('sin organización activa no filtra (consola / super admin)', function () {
    expect(Season::count())->toBe(3);
});
