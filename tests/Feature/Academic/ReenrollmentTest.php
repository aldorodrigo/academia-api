<?php

use App\Actions\Enrollments\TransferSeason;
use App\Actions\Students\ImportStudentRow;
use App\Enums\EnrollmentStatus;
use App\Enums\GroupCriterion;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Enrollments\Pages\ManageEnrollments;
use App\Filament\Resources\Enrollments\Pages\SeasonTransfer;
use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Resources\Students\Schemas\StudentForm;
use App\Filament\Support\EnrollmentForm;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Season;
use App\Models\Student;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    $this->admin = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $this->admin, OrganizationRole::Admin);
    $this->actingAs($this->admin);
    filament()->setTenant($this->jakare);
    app(CurrentOrganization::class)->set($this->jakare);

    $this->s2026 = Season::factory()->for($this->jakare)->create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
    $this->futbol = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $this->sub10 = Group::factory()->for($this->futbol)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id, 'min_age' => 9, 'max_age' => 10]);
    $this->sub12 = Group::factory()->for($this->futbol)->create(['name' => 'Sub-12', 'organization_id' => $this->jakare->id, 'min_age' => 11, 'max_age' => 12]);

    $this->mateo = Student::factory()->for($this->jakare)->create(['first_name' => 'Mateo', 'last_name' => 'Benítez', 'document' => '6123456', 'birth_date' => '2016-03-14']);
    $this->sofia = Student::factory()->for($this->jakare)->create(['first_name' => 'Sofía', 'last_name' => 'Benítez', 'document' => '7234567', 'birth_date' => '2016-07-02']);
    $this->enrollMateo = Enrollment::factory()->create(['student_id' => $this->mateo->id, 'group_id' => $this->sub10->id, 'season_id' => $this->s2026->id]);
    Enrollment::factory()->create(['student_id' => $this->sofia->id, 'group_id' => $this->sub10->id, 'season_id' => $this->s2026->id, 'status' => EnrollmentStatus::Scholarship]);
});

function newSeason(): Season
{
    return Season::factory()->for(test()->jakare)->create(['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'is_current' => true]);
}

describe('inscripciones de temporadas anteriores', function () {
    it('se cierran solas al cambiar la temporada actual', function () {
        // Activo y becado (la beca parcial se cobra con descuento).
        expect(Enrollment::query()->billable()->count())->toBe(2)
            ->and($this->enrollMateo->fresh()->isFinished())->toBeFalse();

        newSeason();

        expect(Enrollment::query()->billable()->count())->toBe(0)
            ->and($this->enrollMateo->fresh()->isFinished())->toBeTrue()
            ->and($this->enrollMateo->fresh()->statusLabel())->toBe('Finalizada');
    });

    it('una baja sigue siendo baja', function () {
        $this->enrollMateo->update(['status' => EnrollmentStatus::Withdrawn]);
        newSeason();

        expect($this->enrollMateo->fresh()->statusLabel())->toBe('Baja');
    });

    it('una finalizada no se puede editar', function () {
        newSeason();

        Livewire::test(ManageEnrollments::class)
            ->filterTable('season', $this->s2026->id)
            ->assertTableActionHidden('changeStatus', $this->enrollMateo)
            ->assertSee('Finalizada');

        Livewire::test(EnrollmentsRelationManager::class, ['ownerRecord' => $this->mateo, 'pageClass' => EditStudent::class])
            ->assertTableActionHidden('edit', $this->enrollMateo)
            ->assertTableActionHidden('delete', $this->enrollMateo);
    });
});

describe('pase de temporada', function () {
    it('propone la categoría por edad y copia el estado', function () {
        $s2027 = newSeason();
        $rows = app(TransferSeason::class)->candidates($this->s2026, $s2027);

        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('group_id')->unique()->all())->toBe([$this->sub12->id])
            ->and($rows->firstWhere('player', 'Benítez, Sofía')['status'])->toBe('becado')
            ->and($rows->every(fn ($row) => $row['include']))->toBeTrue();
    });

    it('reinscribe los tildados, sin duplicar ni tocar a los demás', function () {
        $s2027 = newSeason();
        $rows = app(TransferSeason::class)->candidates($this->s2026, $s2027)
            ->map(fn ($row) => $row['player'] === 'Benítez, Sofía' ? [...$row, 'include' => false] : $row)
            ->all();

        expect(app(TransferSeason::class)->handle($this->s2026, $s2027, $rows))->toBe(1)
            ->and(app(TransferSeason::class)->handle($this->s2026, $s2027, $rows))->toBe(0);

        $new = Enrollment::query()->where('season_id', $s2027->id)->sole();
        expect($new->student_id)->toBe($this->mateo->id)
            ->and($new->group_id)->toBe($this->sub12->id)
            ->and($new->enrolled_on->toDateString())->toBe('2027-01-01')
            ->and($this->sofia->enrollments()->count())->toBe(1);
    });

    it('marca a los ya reinscriptos', function () {
        $s2027 = newSeason();
        Enrollment::factory()->create(['student_id' => $this->mateo->id, 'group_id' => $this->sub12->id, 'season_id' => $s2027->id]);

        $mateo = app(TransferSeason::class)->candidates($this->s2026, $s2027)->firstWhere('player', 'Benítez, Mateo');

        expect($mateo['already'])->toBeTrue()->and($mateo['include'])->toBeFalse();
    });

    it('no toca otra organización', function () {
        $ajena = Organization::factory()->create();
        $s2027 = newSeason();

        app(CurrentOrganization::class)->run($ajena, function () use ($ajena) {
            $student = Student::factory()->for($ajena)->create();
            Enrollment::factory()->create(['student_id' => $student->id]);
        });

        expect(app(TransferSeason::class)->candidates($this->s2026, $s2027))->toHaveCount(2);
    });

    it('la página arma la lista y reinscribe', function () {
        $s2027 = newSeason();

        Livewire::test(ManageEnrollments::class)->assertActionVisible('transfer');

        $page = Livewire::test(SeasonTransfer::class)
            ->assertSet('data.from_season_id', $this->s2026->id)
            ->assertSet('data.to_season_id', $s2027->id);

        expect(collect($page->get('data.rows'))->pluck('group_id')->map(fn ($id) => (int) $id)->unique()->all())->toBe([$this->sub12->id]);

        $page->call('transfer')
            ->assertHasNoFormErrors()
            ->assertNotified('Reinscriptos: 2.')
            ->assertRedirect();

        expect(Enrollment::query()->where('season_id', $s2027->id)->count())->toBe(2);
    });
});

describe('nuevo jugador que ya existe', function () {
    it('por documento avisa y no deja crear', function () {
        Livewire::test(CreateStudent::class)
            ->fillForm(['first_name' => 'Mateo', 'last_name' => 'B', 'document' => '6123456', 'birth_date' => '2016-03-14', 'group_id' => $this->sub10->id])
            ->assertSee('Mateo Benítez ya está cargado (Sub-10 · Fútbol · 2026).')
            ->call('create')
            ->assertHasFormErrors(['document']);

        expect(Student::query()->count())->toBe(2);
    });

    it('por nombre y fecha de nacimiento también', function () {
        Livewire::test(CreateStudent::class)
            ->fillForm(['first_name' => 'Mateo', 'last_name' => 'Benítez', 'birth_date' => '2016-03-14', 'group_id' => $this->sub10->id])
            ->assertSee('Mateo Benítez ya está cargado')
            ->call('create')
            ->assertHasFormErrors(['first_name']);

        expect(Enrollment::query()->count())->toBe(2);
    });

    it('la importación sigue reutilizándolo', function () {
        Guardian::factory()->for($this->jakare)->create(['email' => 'ana@test.com'])->students()->attach($this->mateo);

        app(ImportStudentRow::class)->handle($this->jakare, [
            'first_name' => 'Mateo', 'last_name' => 'Benítez', 'document' => '6123456', 'birth_date' => '14/03/2016',
            'program' => 'Fútbol', 'group' => 'Sub-10',
        ]);

        expect(Student::query()->count())->toBe(2);
    });

    it('el mensaje de bloqueo es el mismo', function () {
        expect(StudentForm::ALREADY_LOADED)->toBe('Ya está cargado: inscribilo desde su ficha.');
    });
});

describe('acción Inscribir', function () {
    it('se abre desde la URL con la categoría sugerida', function () {
        // El navegador monta ?action=enroll al cargar la ficha (wire:init).
        $this->get(EditStudent::getUrl(['record' => $this->mateo, 'action' => 'enroll']))
            ->assertOk()
            ->assertSee("wire:init=\"mountAction('enroll'", false);

        Livewire::test(EditStudent::class, ['record' => $this->mateo->getRouteKey()])
            ->mountAction('enroll')
            ->assertSet('mountedActions.0.data.group_id', $this->sub10->id)
            ->assertSet('mountedActions.0.data.season_id', $this->s2026->id);
    });

    it('con dos disciplinas pide la disciplina, filtra y avisa la otra inscripción', function () {
        $padel = Program::factory()->for($this->jakare)->create(['name' => 'Pádel', 'group_criterion' => GroupCriterion::Level]);
        $inicial = Group::factory()->for($padel)->create(['name' => 'Inicial', 'organization_id' => $this->jakare->id, 'min_age' => null, 'max_age' => null, 'level' => 'Inicial']);

        Livewire::test(EditStudent::class, ['record' => $this->mateo->getRouteKey()])
            ->mountAction('enroll')
            ->assertSchemaComponentExists('program_id', 'mountedActionSchema0')
            ->set('mountedActions.0.data.program_id', $padel->id)
            ->assertSet('mountedActions.0.data.group_id', null)
            ->set('mountedActions.0.data.group_id', $inicial->id)
            ->callMountedAction()
            ->assertHasNoFormErrors();

        expect($this->mateo->enrollments()->count())->toBe(2)
            ->and(EnrollmentForm::otherEnrollmentsNote($this->mateo, $this->s2026->id, $inicial->id))
            ->toBe('También está en Sub-10 · Fútbol.');
    });

    it('no permite repetir la misma categoría y temporada', function () {
        Livewire::test(EditStudent::class, ['record' => $this->mateo->getRouteKey()])
            ->callAction('enroll', data: ['group_id' => $this->sub10->id, 'season_id' => $this->s2026->id, 'status' => 'activo'])
            ->assertHasFormErrors(['group_id']);

        expect($this->mateo->enrollments()->count())->toBe(1);
    });
});
