<?php

use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tenancy\EditOrganizationProfile;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\RelationManagers\GuardiansRelationManager;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Support\Onboarding\Checklist;
use App\Support\Onboarding\Templates;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-04 10:00:00');
    Mail::fake();
});

/** Una academia recién creada con el vocabulario de su tipo (Grupo, Profesor, Sala) y su admin. */
function academy(OrganizationType $type = OrganizationType::Academy, array $terminology = []): array
{
    $organization = Organization::factory()->create([
        'slug' => 'ritmo',
        'name' => 'Academia Ritmo',
        'type' => $type,
        'terminology' => array_merge(Templates::terminologyFor($type), $terminology),
    ]);
    $admin = memberOf($organization);
    app(RoleAssigner::class)->assign($organization, $admin, OrganizationRole::Admin);

    return [$organization, $admin];
}

function teaches(Organization $organization, string ...$programs): void
{
    app(CurrentOrganization::class)->run($organization, function () use ($organization, $programs) {
        foreach ($programs as $name) {
            Program::factory()->for($organization)->create(['name' => $name]);
        }
    });
}

function vocabularyApi(User $user, string $method, string $uri, array $data = [])
{
    return test()->actingAs($user, 'sanctum')->json($method, "/api/v1/{$uri}", $data, ['X-Organization' => 'ritmo']);
}

describe('cuándo es un deporte', function () {
    it('los deportes de equipo, sin mayúsculas ni tildes y por la primera palabra', function () {
        expect(Templates::isSport('Fútbol'))->toBeTrue()
            ->and(Templates::isSport('futbol infantil'))->toBeTrue()
            ->and(Templates::isSport('Fútbol 7'))->toBeTrue()
            ->and(Templates::isSport('BÁSQUET'))->toBeTrue()
            ->and(Templates::isSport('Voley'))->toBeTrue()
            ->and(Templates::isSport('Danza'))->toBeFalse()
            ->and(Templates::isSport('Natación'))->toBeFalse()
            ->and(Templates::isSport('Futbolito de mesa'))->toBeFalse();
    });
});

describe('propuesta en la guía', function () {
    it('una academia que enseña fútbol: categoría, técnico y cancha', function () {
        [$organization, $admin] = academy();
        teaches($organization, 'Fútbol', 'Danza');

        vocabularyApi($admin, 'GET', 'onboarding')->assertOk()
            ->assertJsonPath('data.terminology_suggestion', [
                'programs' => ['Fútbol'],
                'current' => ['group' => 'Grupo', 'instructor' => 'Profesor', 'space' => 'Sala'],
                'suggested' => ['group' => 'Categoría', 'instructor' => 'Técnico', 'space' => 'Cancha'],
            ])
            ->assertJsonPath('data.steps.0.description', 'Disciplinas que ofrece la academia.');
    });

    it('sin deporte, en un club o con el vocabulario ya decidido no hay propuesta', function () {
        [$organization, $admin] = academy();
        teaches($organization, 'Danza');
        vocabularyApi($admin, 'GET', 'onboarding')->assertJsonPath('data.terminology_suggestion', null);

        $club = Organization::factory()->create(['type' => OrganizationType::Club]);
        teaches($club, 'Fútbol');
        expect(Checklist::for($club)->toArray()['terminology_suggestion'])->toBeNull()
            ->and(Checklist::for($club)->toArray()['steps'][0]['description'])->toBe('Disciplinas que ofrece el club.');

        teaches($organization, 'Fútbol');
        $organization->forceFill(['terminology_confirmed_at' => now()])->save();
        vocabularyApi($admin, 'GET', 'onboarding')->assertJsonPath('data.terminology_suggestion', null);
    });

    it('solo propone las palabras que siguen como vinieron con el tipo', function () {
        [$organization, $admin] = academy(OrganizationType::School, ['group' => 'Nivel']);
        teaches($organization, 'Vóley');

        vocabularyApi($admin, 'GET', 'onboarding')
            ->assertJsonPath('data.terminology_suggestion.current', ['instructor' => 'Profesor', 'space' => 'Aula'])
            ->assertJsonPath('data.terminology_suggestion.suggested', ['instructor' => 'Técnico', 'space' => 'Cancha'])
            ->assertJsonPath('data.steps.0.description', 'Disciplinas que ofrece la escuela.');
    });
});

describe('cómo les dicen (API)', function () {
    it('usar las palabras propuestas (o las que elija) y no volver a proponer', function () {
        [$organization, $admin] = academy();
        teaches($organization, 'Fútbol');

        vocabularyApi($admin, 'PUT', 'organization/terminology', [
            'terminology' => ['group' => 'Categoría', 'instructor' => 'profesor', 'space' => 'Cancha'],
        ])->assertOk()->assertExactJson(['data' => ['terminology' => [
            'program' => 'Disciplina', 'group' => 'Categoría', 'student' => 'Alumno',
            'instructor' => 'Profesor', 'guardian' => 'Tutor', 'space' => 'Cancha',
        ]]]);

        expect($organization->refresh()->term('group'))->toBe('Categoría')
            ->and($organization->terminology_confirmed_at)->not->toBeNull();
        vocabularyApi($admin, 'GET', 'onboarding')
            ->assertJsonPath('data.terminology_suggestion', null)
            ->assertJsonPath('data.steps.1.title', 'Categorías y horarios');
        vocabularyApi($admin, 'GET', 'organization')->assertJsonPath('data.terminology.space', 'Cancha');
    });

    it('"Dejar como estaba" no cambia nada y no vuelve a proponer', function () {
        [$organization, $admin] = academy();
        teaches($organization, 'Fútbol');

        vocabularyApi($admin, 'PUT', 'organization/terminology', ['terminology' => []])
            ->assertOk()->assertJsonPath('data.terminology.group', 'Grupo');

        expect($organization->refresh()->term('instructor'))->toBe('Profesor');
        vocabularyApi($admin, 'GET', 'onboarding')->assertJsonPath('data.terminology_suggestion', null);
    });

    it('vacía vuelve a la del tipo; valida claves y largo; solo quien configura', function () {
        [$organization, $admin] = academy(terminology: ['group' => 'Nivel']);

        vocabularyApi($admin, 'PUT', 'organization/terminology', ['terminology' => ['group' => '']])
            ->assertOk()->assertJsonPath('data.terminology.group', 'Grupo');
        vocabularyApi($admin, 'PUT', 'organization/terminology', ['terminology' => ['color' => 'Verde']])
            ->assertUnprocessable();
        vocabularyApi($admin, 'PUT', 'organization/terminology', ['terminology' => ['group' => str_repeat('a', 31)]])
            ->assertUnprocessable()->assertJsonValidationErrors(['terminology.group' => 'hasta 30']);
        vocabularyApi($admin, 'PUT', 'organization/terminology', [])->assertUnprocessable();

        $tutor = memberOf($organization);
        app(RoleAssigner::class)->assign($organization, $tutor, OrganizationRole::Guardian);
        vocabularyApi($tutor, 'PUT', 'organization/terminology', ['terminology' => ['group' => 'Clase']])->assertForbidden();
    });

    it('crear la organización con palabras propias ya cuenta como decidido', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/organizations', [
            'name' => 'Academia Ritmo', 'type' => 'academy', 'slug' => 'ritmo',
            'terminology' => ['group' => 'Categoría'],
        ])->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/organizations', [
            'name' => 'Academia Sol', 'type' => 'academy', 'slug' => 'sol',
            'terminology' => ['group' => 'Grupo', 'student' => 'Alumno'],
        ])->assertCreated();

        expect(Organization::query()->where('slug', 'ritmo')->value('terminology_confirmed_at'))->not->toBeNull()
            ->and(Organization::query()->where('slug', 'sol')->value('terminology_confirmed_at'))->toBeNull();
    });
});

describe('panel', function () {
    beforeEach(function () {
        filament()->setCurrentPanel(filament()->getPanel('admin'));
    });

    function panelAs(User $user, Organization $organization): void
    {
        test()->actingAs($user);
        filament()->setTenant($organization);
        app(CurrentOrganization::class)->set($organization);
    }

    it('al elegir fútbol en la guía propone las palabras y guarda las elegidas', function () {
        [$organization, $admin] = academy();
        panelAs($admin, $organization);

        Livewire::test(Dashboard::class)
            ->assertSee('Configurá tu academia')
            ->assertDontSee('Configurá tu club')
            ->callAction('programs', data: ['programs' => ['Fútbol']])
            ->assertActionMounted('terminology')
            ->assertActionDataSet(['terminology.group' => 'Categoría', 'terminology.space' => 'Cancha'])
            ->setActionData(['terminology' => ['group' => 'Categoría', 'instructor' => 'Entrenador', 'space' => 'Cancha']])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $organization->refresh();
        expect($organization->term('group'))->toBe('Categoría')
            ->and($organization->term('instructor'))->toBe('Entrenador')
            ->and($organization->terminology_confirmed_at)->not->toBeNull();
    });

    it('"Dejar como estaba" desde el panel', function () {
        [$organization, $admin] = academy();
        panelAs($admin, $organization);

        Livewire::test(Dashboard::class)
            ->callAction('programs', data: ['programs' => ['Básquet']])
            ->assertActionMounted('terminology')
            ->callAction('keepTerminology');

        $organization->refresh();
        expect($organization->term('group'))->toBe('Grupo')
            ->and($organization->terminology_confirmed_at)->not->toBeNull();
    });

    it('sin deporte sigue directo', function () {
        [$organization, $admin] = academy();
        panelAs($admin, $organization);

        Livewire::test(Dashboard::class)
            ->callAction('programs', data: ['programs' => ['Danza']])
            ->assertActionNotMounted('terminology');
    });

    it('cambiar el vocabulario en Configuración lo confirma', function () {
        [$organization, $admin] = academy();
        teaches($organization, 'Fútbol');
        panelAs($admin, $organization);

        Livewire::test(EditOrganizationProfile::class)
            ->fillForm(['terminology.instructor' => 'Técnico'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($organization->refresh()->term('instructor'))->toBe('Técnico')
            ->and($organization->terminology_confirmed_at)->not->toBeNull();
    });

    it('la ficha del alumno dice "Crear tutor" y usa el vocabulario', function () {
        [$organization, $admin] = academy(terminology: ['guardian' => 'Responsable']);
        panelAs($admin, $organization);
        $student = app(CurrentOrganization::class)->run($organization, fn () => Student::factory()->for($organization)->create());

        Livewire::test(GuardiansRelationManager::class, ['ownerRecord' => $student, 'pageClass' => EditStudent::class])
            ->assertOk()
            ->assertSee('Crear responsable')
            ->assertDontSee('guardian');
    });
});
