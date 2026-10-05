<?php

use App\Enums\OrganizationRole;
use App\Enums\SeasonKind;
use App\Filament\Actions\SpreadsheetImportAction;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Filament\Resources\Seasons\Support\SeasonPlan;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Models\Group;
use App\Models\OnboardingDraft;
use App\Models\Organization;
use App\Models\Program;
use App\Models\User;
use App\Support\Onboarding\StepDrafts;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Vocabulary;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    $this->travelTo('2026-10-03 10:00:00');
    Mail::fake();
    $this->org = Organization::factory()->create(['slug' => 'ritmo', 'name' => 'Academia Ritmo']);
    $this->admin = memberOf($this->org);
    app(RoleAssigner::class)->assign($this->org, $this->admin, OrganizationRole::Admin);
    $this->futbol = app(CurrentOrganization::class)->run($this->org, fn () => Program::factory()->for($this->org)->create(['name' => 'Fútbol']));
});

function stepsApi(string $method, string $uri, array $data = [], ?User $user = null)
{
    return test()->actingAs($user ?? test()->admin, 'sanctum')->json($method, "/api/v1/{$uri}", $data, ['X-Organization' => 'ritmo']);
}

function groupsDraft(int $programId, array $names = ['Sub-8', 'Sub-10']): array
{
    return [
        'program_id' => $programId,
        'ages' => ['from' => 5, 'to' => 16, 'span' => 2],
        'levels' => ['Inicial'],
        'capacity' => 20,
        'groups' => array_map(fn (string $name) => [
            'name' => $name, 'min_age' => 7, 'max_age' => 8, 'level' => null,
            'slots' => [['weekdays' => [4, 2], 'starts_at' => '17:00:00', 'ends_at' => '18:30', 'venue_id' => null, 'otro' => 'x']],
        ], $names),
        'basura' => 'no se guarda',
    ];
}

describe('E3 · borrador del paso 2 en el servidor', function () {
    it('se guarda, se recupera y queda como usado al crear las categorías (sin borrarlo)', function () {
        stepsApi('GET', 'onboarding/steps/groups/draft')->assertOk()->assertJsonPath('data.draft', null);

        stepsApi('PUT', 'onboarding/steps/groups/draft', ['draft' => groupsDraft($this->futbol->id)])->assertOk();
        stepsApi('PUT', 'onboarding/steps/groups/draft', ['draft' => groupsDraft($this->futbol->id, ['Sub-8'])])->assertOk();

        $draft = stepsApi('GET', 'onboarding/steps/groups/draft')->assertOk()->json('data.draft');
        expect($draft)->not->toHaveKey('basura')
            ->and($draft['groups'])->toHaveCount(1)
            ->and($draft['groups'][0]['slots'][0])->toBe(['weekdays' => [2, 4], 'starts_at' => '17:00', 'ends_at' => '18:30', 'venue_id' => null])
            ->and(OnboardingDraft::withoutGlobalScopes()->count())->toBe(1);

        stepsApi('POST', 'setup/groups', ['program_id' => $this->futbol->id, 'groups' => [['name' => 'Sub-8', 'schedules' => []]]])->assertCreated();

        stepsApi('GET', 'onboarding/steps/groups/draft')->assertJsonPath('data.draft', null);
        expect(OnboardingDraft::withoutGlobalScopes()->withTrashed()->sole()->trashed())->toBeTrue();
    });

    it('vaciarlo lo marca como usado; otra organización no lo ve; sin permiso, 403', function () {
        stepsApi('PUT', 'onboarding/steps/groups/draft', ['draft' => groupsDraft($this->futbol->id)])->assertOk();

        $ajena = Organization::factory()->create(['slug' => 'ajena']);
        $otro = memberOf($ajena);
        app(RoleAssigner::class)->assign($ajena, $otro, OrganizationRole::Admin);
        $this->actingAs($otro, 'sanctum')->getJson('/api/v1/onboarding/steps/groups/draft', ['X-Organization' => 'ajena'])
            ->assertJsonPath('data.draft', null);

        $tutor = memberOf($this->org);
        app(RoleAssigner::class)->assign($this->org, $tutor, OrganizationRole::Guardian);
        stepsApi('GET', 'onboarding/steps/groups/draft', user: $tutor)->assertForbidden();
        stepsApi('GET', 'onboarding/steps/season/draft')->assertNotFound();

        stepsApi('DELETE', 'onboarding/steps/groups/draft')->assertNoContent();
        stepsApi('GET', 'onboarding/steps/groups/draft')->assertJsonPath('data.draft', null);
        expect(OnboardingDraft::withoutGlobalScopes()->withTrashed()->count())->toBe(1);
    });

    it('el panel retoma lo que quedó en la app y guarda sus cambios en el mismo borrador', function () {
        StepDrafts::put($this->org, 'groups', groupsDraft($this->futbol->id, ['Sub-9', 'Sub-11']));
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $this->actingAs($this->admin);
        filament()->setTenant($this->org);
        app(CurrentOrganization::class)->set($this->org);

        Livewire::test(Dashboard::class)
            ->mountAction('groups')
            ->assertActionDataSet(fn (array $data) => collect($data['groups'])->pluck('name')->all() === ['Sub-9', 'Sub-11']
                && $data['capacity'] === 20)
            // Como llega del navegador (un cambio en el formulario).
            ->set('mountedActions.0.data.groups', [['name' => 'Sub-13', 'min_age' => 12, 'max_age' => 13, 'level' => null]]);

        expect(collect(StepDrafts::get($this->org, 'groups')['draft']['groups'])->pluck('name')->all())->toBe(['Sub-13']);
    });
});

describe('E6 · vencimiento para los que se inscriben hoy', function () {
    it('la vista previa usa la misma regla que las cuotas: max(vencimiento, inscripción + días)', function () {
        $state = [
            'name' => 'Temporada 2026', 'kind' => 'anual', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
            'fee_frequency' => 'mensual', 'due_days' => 9, 'fee_amount' => 150000,
        ];

        $examples = SeasonPlan::examples($state, today: CarbonImmutable::parse('2026-01-03'));
        expect($examples[0])->toMatchArray(['due_on' => '12/01/2026', 'due_note' => 'para los que se inscriben hoy'])
            ->and($examples[1])->toMatchArray(['due_on' => '10/02/2026', 'due_note' => null]);

        // Antes de empezar, el vencimiento de siempre.
        expect(SeasonPlan::examples($state, today: CarbonImmutable::parse('2025-12-20'))[0]['due_note'])->toBeNull();
        // Si se cobra desde el próximo período, el de hoy no cambia.
        expect(SeasonPlan::examples([...$state, 'mid_period' => 'proximo'], today: CarbonImmutable::parse('2026-01-03'))[0]['due_on'])->toBe('10/01/2026');

        $this->travelTo('2026-01-03 10:00:00');
        stepsApi('POST', 'setup/seasons/preview', [...$state, 'program_ids' => [$this->futbol->id], 'issue_upfront' => false, 'mid_period' => 'completo'])
            ->assertOk()
            ->assertJsonPath('data.examples.0.due_on', '12/01/2026')
            ->assertJsonPath('data.examples.0.due_note', 'para los que se inscriben hoy');
    });
});

describe('detalles', function () {
    it('D1 · "Yo también doy clases" pide elegir al menos una', function () {
        app(CurrentOrganization::class)->run($this->org, fn () => Group::factory()->for($this->futbol)->create(['organization_id' => $this->org->id, 'name' => 'Sub-8']));

        stepsApi('PUT', 'setup/instructors/me', ['teaches' => true, 'group_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['group_ids' => 'Elegí al menos una categoría.']);
        stepsApi('PUT', 'setup/instructors/me', ['teaches' => false])->assertOk();
    });

    it('D4 y D5 · plurales y "Temporada" sin repetir', function () {
        expect(Vocabulary::count(1, 'familia', 'familias'))->toBe('1 familia')
            ->and(Vocabulary::count(2, 'familia', 'familias'))->toBe('2 familias')
            ->and(Vocabulary::season('2026'))->toBe('Temporada 2026')
            ->and(Vocabulary::season('Temporada 2026'))->toBe('Temporada 2026')
            ->and(SeasonKind::Annual->suggestedName(CarbonImmutable::parse('2027-01-01')))->toBe('Temporada 2027')
            ->and(SeasonPlan::allPeriods(1))->toBe('la cuota')
            ->and(SeasonPlan::allPeriods(12))->toBe('las 12 cuotas');
    });

    it('D7 · panel: marca Tuku, voseo, Roles en Personas y mayúsculas', function () {
        expect(filament()->getPanel('admin')->getBrandName())->toBe('Tuku')
            ->and(__('filament-panels::auth/pages/login.heading'))->toBe('Entrá a tu cuenta')
            ->and(__('filament-panels::auth/pages/login.actions.request_password_reset.label'))->toBe('¿Olvidaste tu contraseña?')
            // Lo demás del archivo sigue viniendo de Filament.
            ->and(__('filament-panels::auth/pages/login.form.password.label'))->toBe('Contraseña')
            ->and(__('filament-forms::components.select.placeholder'))->toBe('Elegí una opción')
            ->and(__('filament-notifications::database.modal.empty.description'))->toBe('Fijate de nuevo más tarde.');

        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $this->actingAs($this->admin);
        filament()->setTenant($this->org);
        expect(RoleResource::getNavigationGroup())->toBe('Personas')
            ->and(RecurringExpenseResource::getNavigationLabel())->toBe('Gastos recurrentes');
    });

    it('D7 · importar acepta Excel (.xlsx) además de CSV', function () {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['nombre', 'apellido', 'nacimiento']));
        $writer->addRow(Row::fromValues(['Ana', 'Benítez', '2016-05-10']));
        $writer->addRow(Row::fromValues(['', '', '']));
        $writer->close();

        $csv = SpreadsheetImportAction::xlsxToCsv($path);
        expect(stream_get_contents($csv))->toBe("nombre,apellido,nacimiento\nAna,Benítez,2016-05-10\n");

        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $this->actingAs($this->admin);
        filament()->setTenant($this->org);
        app(CurrentOrganization::class)->set($this->org);

        Livewire::test(ListStudents::class)
            ->mountAction('import')
            ->setActionData(['file' => UploadedFile::fake()->createWithContent('jugadores.xlsx', file_get_contents($path))])
            ->assertHasNoActionErrors(['file'])
            ->assertActionDataSet(['columnMap.first_name' => 'nombre']);
    });
});
