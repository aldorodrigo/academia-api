<?php

use App\Enums\FeeFrequency;
use App\Enums\MidPeriod;
use App\Enums\OrganizationRole;
use App\Enums\SeasonKind;
use App\Filament\Resources\Seasons\Pages\CreateSeason;
use App\Filament\Resources\Seasons\Pages\EditSeason;
use App\Filament\Resources\Seasons\Pages\ListSeasons;
use App\Filament\Resources\Seasons\Support\SeasonPlan;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Support\MidPeriodPreview;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->travelTo('2026-10-15 12:00:00');
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    $this->admin = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $this->admin, OrganizationRole::Admin);
    $this->actingAs($this->admin);
    filament()->setTenant($this->jakare);
    app(CurrentOrganization::class)->set($this->jakare);

    $this->futbol = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $this->sub10 = Group::factory()->for($this->futbol)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id]);
    $this->sub17 = Group::factory()->for($this->futbol)->create(['name' => 'Sub-17', 'organization_id' => $this->jakare->id]);
});

it('propone una temporada anual con cuota mensual y calcula fin y nombre según la duración', function () {
    Livewire::test(CreateSeason::class)
        ->assertSchemaStateSet([
            'kind' => SeasonKind::Annual,
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'name' => '2027',
            'fee_frequency' => FeeFrequency::Monthly,
            'due_days' => 9,
            'issue_upfront' => '0',
            'program_ids' => [$this->futbol->id],
        ])
        ->fillForm(['kind' => 'quincenal'])
        ->assertSchemaStateSet(['ends_on' => '2027-01-15', 'name' => '1.ª quincena de enero 2027', 'fee_frequency' => FeeFrequency::Weekly, 'due_days' => 3]);
});

it('crea la temporada con sus montos y el resumen coincide con lo que se emite', function () {
    Livewire::test(CreateSeason::class)
        ->fillForm([
            'fee_amount' => 150000,
            'enrollment_fee_amount' => 100000,
            'has_group_amounts' => true,
            'group_amounts' => [['group_id' => $this->sub17->id, 'amount' => 180000]],
            'issue_upfront' => '1',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $season = Season::query()->sole();
    expect($season->fee_frequency->value)->toBe('mensual')
        ->and($season->issue_upfront)->toBeTrue()
        ->and($season->programs->pluck('name')->all())->toBe(['Fútbol'])
        ->and($season->tariffs()->orderBy('amount')->get()->map(fn (Tariff $t) => [$t->group_id, $t->amount])->all())
        ->toBe([[null, 100000], [null, 150000], [$this->sub17->id, 180000]]);

    $state = ['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'fee_frequency' => 'mensual', 'due_days' => 9, 'issue_upfront' => '1', 'fee_amount' => 150000, 'program_ids' => [$this->futbol->id]];
    expect(SeasonPlan::summary($state))->toBe('2027 de Fútbol, del 01/01/2027 al 31/12/2027. Cuota mensual de ₲ 150.000, que vence el día 10 de cada mes. Las 12 cuotas de cada jugador se crean todas al inscribirlo.')
        ->and(SeasonPlan::examples($state)->first())->toBe(['period' => 'enero 2027', 'due_on' => '10/01/2027', 'amount' => '₲ 150.000']);

    // Lo que después se emite coincide con el ejemplo.
    Enrollment::factory()->create(['student_id' => Student::factory()->for($this->jakare)->create()->id, 'group_id' => $this->sub10->id, 'season_id' => $season->id, 'enrolled_on' => '2026-10-15']);
    $first = Charge::query()->where('fee_concept_id', FeeConcept::monthlyFee($this->jakare)->id)->orderBy('period_start')->first();
    expect([$first->due_on->format('d/m/Y'), $first->base_amount])->toBe(['10/01/2027', 150000]);
});

it('copia la temporada anterior con aumento y ofrece pasar a los jugadores', function () {
    $old = Season::factory()->for($this->jakare)->create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'fee_frequency' => 'mensual']);
    $old->programs()->attach($this->futbol);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $old->id, 'amount' => 150000, 'valid_from' => '2026-01-01']);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $old->id, 'group_id' => $this->sub17->id, 'amount' => 170000, 'valid_from' => '2026-01-01']);
    Enrollment::factory()->count(2)->create(['season_id' => $old->id, 'group_id' => $this->sub10->id, 'student_id' => fn () => Student::factory()->for($this->jakare)->create()->id]);

    Livewire::test(CreateSeason::class)
        ->fillForm(['copy_from' => (string) $old->id])
        ->assertSchemaStateSet(['name' => '2027', 'starts_on' => '2027-01-01', 'fee_amount' => 150000, 'has_group_amounts' => true])
        ->fillForm(['increase_percent' => 10])
        ->assertSchemaStateSet(['fee_amount' => 165000])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Temporada 2027 creada.');

    $new = Season::query()->where('name', '2027')->sole();
    expect($new->tariffs()->orderBy('amount')->pluck('amount')->all())->toBe([165000, 187000]);
});

it('sin permiso de tarifas no ve los pasos de cobro y la temporada queda sin plan', function () {
    $secretary = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $secretary, OrganizationRole::Secretary, endsOn: now()->addYear());
    app(CurrentOrganization::class)->set($this->jakare);
    $permissions = collect(['ViewAny:Season', 'Create:Season', 'Update:Season'])->each(fn (string $name) => Permission::findOrCreate($name))->all();
    Role::query()->where('organization_id', $this->jakare->id)->where('name', 'secretario')->sole()->givePermissionTo($permissions);
    $this->actingAs($secretary);

    Livewire::test(CreateSeason::class)
        ->assertDontSee('¿Cada cuánto se cobra?')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Season::query()->sole()->hasFeePlan())->toBeFalse()
        ->and(Tariff::query()->count())->toBe(0);

    // Después la completa quien puede cargar tarifas.
    $this->actingAs($this->admin);
    Livewire::test(ListSeasons::class)
        ->callTableAction('configurePlan', Season::query()->sole(), data: ['fee_frequency' => 'semanal', 'due_days' => 3, 'fee_amount' => 40000, 'issue_upfront' => '0', 'mid_period' => 'proporcional'])
        ->assertHasNoTableActionErrors();

    expect(Season::query()->sole())->fee_frequency->value->toBe('semanal')
        ->and(Tariff::query()->sole()->amount)->toBe(40000);
});

it('la frecuencia queda bloqueada cuando ya hay cuotas', function () {
    $season = Season::factory()->for($this->jakare)->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'fee_frequency' => 'mensual']);
    Charge::factory()->create(['student_id' => Student::factory()->for($this->jakare)->create()->id, 'season_id' => $season->id]);

    Livewire::test(EditSeason::class, ['record' => $season->getRouteKey()])
        ->assertFormFieldDisabled('fee_frequency');
});

it('al inscribir a mitad de mes pregunta qué se cobra y muestra el efecto', function () {
    $season = Season::factory()->for($this->jakare)->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'fee_frequency' => 'mensual', 'mid_period' => 'proporcional']);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $season->id, 'amount' => 310000, 'valid_from' => '2026-01-01']);
    $student = Student::factory()->for($this->jakare)->create(['birth_date' => '2016-03-14']);

    Livewire::test(EnrollmentsRelationManager::class, ['ownerRecord' => $student, 'pageClass' => EditStudent::class])
        ->mountTableAction('create')
        ->setTableActionData(['group_id' => $this->sub10->id, 'enrolled_on' => '2026-10-22'])
        ->assertTableActionDataSet(['season_id' => $season->id, 'mid_period' => MidPeriod::Prorated->value])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    // Del 22 al 31 de octubre: 10 de 31 días.
    expect(Charge::query()->sole()->base_amount)->toBe(100000);
});

it('al inscribir a mitad de quincena o por día lo dice con la unidad', function () {
    $fortnight = Season::factory()->for($this->jakare)->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'fee_frequency' => 'quincenal', 'due_days' => 3, 'mid_period' => 'completo']);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $fortnight->id, 'amount' => 80000, 'valid_from' => '2026-01-01']);
    $get = function (array $data) {
        $get = Mockery::mock(Get::class);
        $get->shouldReceive('__invoke')->andReturnUsing(fn (string $key) => $data[$key] ?? null);

        return $get;
    };
    $state = ['season_id' => $fortnight->id, 'group_id' => $this->sub10->id, 'enrolled_on' => '2026-10-20'];

    expect(MidPeriodPreview::text($get([...$state, 'mid_period' => 'completo'])))->toBe('Se cobrará la quincena completa: ₲ 80.000.')
        ->and(MidPeriodPreview::text($get([...$state, 'mid_period' => 'proximo'])))->toBe('No se cobra esta quincena: se empieza a cobrar desde el 01/11.');

    // Por día de entrenamiento: en entrenamientos, no en días corridos.
    $daily = Season::factory()->for($this->jakare)->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'fee_frequency' => 'diaria', 'daily_basis' => 'entrenamiento', 'daily_grouping' => 'mes', 'due_days' => 5, 'mid_period' => 'proporcional']);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $daily->id, 'amount' => 20000, 'valid_from' => '2026-01-01']);
    Schedule::query()->create(['group_id' => $this->sub10->id, 'weekday' => 2, 'starts_at' => '17:00', 'ends_at' => '18:00']);

    expect(MidPeriodPreview::text($get(['season_id' => $daily->id, 'group_id' => $this->sub10->id, 'enrolled_on' => '2026-10-20'])))
        ->toBe('Se cobrará ₲ 40.000 (2 de 4 entrenamientos).');

    // Agrupado por día no hay "mitad de…".
    $daily->update(['daily_grouping' => 'dia']);
    expect(MidPeriodPreview::text($get(['season_id' => $daily->id, 'group_id' => $this->sub10->id, 'enrolled_on' => '2026-10-20'])))->toBeNull();
});
