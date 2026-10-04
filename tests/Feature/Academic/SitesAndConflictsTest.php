<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Site;
use App\Models\Venue;
use App\Support\Roles\RoleAssigner;
use App\Support\Scheduling\ScheduleConflicts;
use App\Support\Tenancy\CurrentOrganization;
use Livewire\Livewire;

beforeEach(function () {
    $this->club = Organization::factory()->create(['slug' => 'ritmo']);
    app(CurrentOrganization::class)->set($this->club);
    $this->admin = memberOf($this->club, ['name' => 'Laura Gómez']);
    app(RoleAssigner::class)->assign($this->club, $this->admin, OrganizationRole::Admin);

    $this->poli = Site::query()->create(['name' => 'Polideportivo', 'address' => 'Av. España 123']);
    $this->cancha1 = Venue::query()->create(['site_id' => $this->poli->id, 'name' => 'Cancha 1']);
    $this->cancha2 = Venue::query()->create(['site_id' => $this->poli->id, 'name' => 'Cancha 2']);
    $futbol = Program::factory()->for($this->club)->create(['name' => 'Fútbol']);
    $this->sub10 = Group::factory()->for($futbol)->create(['organization_id' => $this->club->id, 'name' => 'Sub-10']);
    Schedule::query()->create(['group_id' => $this->sub10->id, 'weekday' => 2, 'starts_at' => '17:00', 'ends_at' => '18:30', 'venue_id' => $this->cancha1->id]);
    $this->futbol = $futbol;
});

function setupCall(string $method, string $uri, array $data = [])
{
    return test()->actingAs(test()->admin, 'sanctum')->json($method, "/api/v1/{$uri}", $data, ['X-Organization' => 'ritmo']);
}

describe('lugares con varias canchas', function () {
    it('muestra la cancha con su lugar; con una sola, solo el lugar', function () {
        $club = Venue::query()->create(['name' => 'Cancha del club']);

        expect($this->cancha2->label)->toBe('Polideportivo · Cancha 2')
            ->and($club->label)->toBe('Cancha del club')
            ->and($club->site->name)->toBe('Cancha del club');

        setupCall('GET', 'venues')->assertJsonFragment(['id' => $this->cancha2->id, 'name' => 'Polideportivo · Cancha 2']);
    });

    it('crea un lugar con sus canchas, o con una del mismo nombre', function () {
        setupCall('POST', 'setup/sites', ['name' => 'Club Sajonia', 'address' => 'Sajonia', 'spaces' => ['Cancha A', 'Cancha B']])
            ->assertCreated()
            ->assertJsonPath('data.spaces.1.label', 'Club Sajonia · Cancha B');

        setupCall('POST', 'setup/sites', ['name' => 'Gimnasio'])
            ->assertCreated()
            ->assertJsonPath('data.spaces.0.label', 'Gimnasio');

        setupCall('POST', "setup/sites/{$this->poli->id}/spaces", ['name' => 'Cancha 3'])
            ->assertCreated()
            ->assertJsonCount(3, 'data.spaces');
        setupCall('POST', "setup/sites/{$this->poli->id}/spaces", ['name' => 'Cancha 3'])
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'Ya existe en ese lugar.');

        setupCall('GET', 'setup/sites')->assertJsonPath('data.2.name', 'Polideportivo');
    });

    it('"Cancha 1" se puede repetir en lugares distintos', function () {
        $otro = Site::query()->create(['name' => 'Club Sajonia']);

        expect(Venue::query()->create(['site_id' => $otro->id, 'name' => 'Cancha 1'])->label)->toBe('Club Sajonia · Cancha 1');
    });
});

describe('choques de horarios', function () {
    it('avisa si otra categoría usa la misma cancha a esa hora', function () {
        setupCall('POST', 'setup/schedules/conflicts', ['schedules' => [
            ['key' => '0-0', 'group_name' => 'Sub-8', 'weekday' => 2, 'starts_at' => '17:30', 'ends_at' => '18:30', 'venue_id' => $this->cancha1->id],
            // Otra cancha del mismo lugar: no choca.
            ['key' => '1-0', 'group_name' => 'Sub-12', 'weekday' => 2, 'starts_at' => '17:00', 'ends_at' => '18:30', 'venue_id' => $this->cancha2->id],
            // Termina justo cuando empieza la otra: no choca.
            ['key' => '2-0', 'group_name' => 'Sub-14', 'weekday' => 2, 'starts_at' => '18:30', 'ends_at' => '20:00', 'venue_id' => $this->cancha1->id],
        ]])
            ->assertOk()
            ->assertExactJson(['data' => ['0-0' => ['Choca con Sub-10 el martes de 17:00 a 18:30 en Polideportivo · Cancha 1.']]]);
    });

    it('avisa entre las que se están cargando, y no consigo misma', function () {
        $warnings = ScheduleConflicts::forSlots([
            ['key' => 'a', 'group_name' => 'Sub-8', 'weekday' => 4, 'starts_at' => '17:00', 'ends_at' => '18:00', 'venue_id' => $this->cancha2->id],
            ['key' => 'b', 'group_name' => 'Sub-12', 'weekday' => 4, 'starts_at' => '17:30', 'ends_at' => '19:00', 'venue_id' => $this->cancha2->id],
            // Editando Sub-10: su horario guardado no cuenta.
            ['key' => 'c', 'group_id' => $this->sub10->id, 'group_name' => 'Sub-10', 'weekday' => 2, 'starts_at' => '17:00', 'ends_at' => '18:30', 'venue_id' => $this->cancha1->id],
        ]);

        expect($warnings)->toBe([
            'a' => ['Choca con Sub-12 el jueves de 17:30 a 19:00 en Polideportivo · Cancha 2.'],
            'b' => ['Choca con Sub-8 el jueves de 17:00 a 18:00 en Polideportivo · Cancha 2.'],
        ]);
    });

    it('avisa si un técnico queda con dos categorías a la misma hora', function () {
        $sub12 = Group::factory()->for($this->futbol)->create(['organization_id' => $this->club->id, 'name' => 'Sub-12']);
        Schedule::query()->create(['group_id' => $sub12->id, 'weekday' => 2, 'starts_at' => '18:00', 'ends_at' => '19:00', 'venue_id' => $this->cancha2->id]);

        setupCall('PUT', 'setup/instructors/me', ['teaches' => true, 'group_ids' => [$this->sub10->id, $sub12->id]])
            ->assertOk()
            ->assertJsonPath('warnings', ['Laura Gómez tiene Sub-10 y Sub-12 el martes a las 18:00.']);

        setupCall('PUT', 'setup/instructors/me', ['teaches' => true, 'group_ids' => [$this->sub10->id]])
            ->assertJsonPath('warnings', []);
    });

    it('el formulario de la categoría avisa y deja guardar', function () {
        $sub12 = Group::factory()->for($this->futbol)->create(['organization_id' => $this->club->id, 'name' => 'Sub-12']);
        $this->actingAs($this->admin);
        filament()->setTenant($this->club);

        Livewire::test(EditGroup::class, ['record' => $sub12->getRouteKey()])
            ->set('data.schedules', ['nuevo' => ['weekday' => 2, 'starts_at' => '17:30', 'ends_at' => '19:00', 'venue_id' => $this->cancha1->id]])
            ->assertSee('Ojo, se superponen')
            ->assertSee('Sub-12: Choca con Sub-10 el martes de 17:00 a 18:30 en Polideportivo · Cancha 1.')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($sub12->schedules()->count())->toBe(1);
    });

    it('avisa al reprogramar una clase si ese día otra categoría usa la cancha', function () {
        $sub12 = Group::factory()->for($this->futbol)->create(['organization_id' => $this->club->id, 'name' => 'Sub-12']);
        // Martes 06/10/2026 de 17:30 a 18:30 en Cancha 1 (Sub-10 entrena ahí de 17:00 a 18:30).
        $makeup = ClassSession::query()->create([
            'group_id' => $sub12->id, 'date' => '2026-10-06', 'starts_at' => '17:30', 'ends_at' => '18:30',
            'venue_id' => $this->cancha1->id, 'is_makeup' => true,
        ]);

        expect(ScheduleConflicts::forClass($makeup))->toBe(['Ese día Sub-10 usa Polideportivo · Cancha 1 de 17:00 a 18:30.'])
            ->and(ScheduleConflicts::forClass(tap($makeup)->update(['venue_id' => $this->cancha2->id])->fresh()))->toBe([]);
    });
});
