<?php

use App\Enums\EnrollmentStatus;
use App\Enums\OrganizationRole;
use App\Filament\Imports\StudentImporter;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\ManageEnrollments;
use App\Filament\Resources\Groups\Pages\CreateGroup;
use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Filament\Resources\Students\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Resources\Students\RelationManagers\GuardiansRelationManager;
use App\Mail\InvitationMail;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Role;
use App\Models\Season;
use App\Models\Student;
use App\Models\User;
use App\Models\Venue;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Mail::fake();
    $this->jakare = Organization::factory()->create(['name' => 'Club Jakare', 'slug' => 'jakare']);
    $this->ajena = Organization::factory()->create(['slug' => 'ajena']);
    $this->admin = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $this->admin, OrganizationRole::Admin);

    Season::factory()->for($this->jakare)->create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $this->program = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
});

function inPanel(User $user, Organization $organization): void
{
    test()->actingAs($user);
    filament()->setTenant($organization);
    app(CurrentOrganization::class)->set($organization);
}

it('crea un grupo con horarios e instructores', function () {
    $instructor = memberOf($this->jakare, ['name' => 'Carlos Gómez']);
    app(RoleAssigner::class)->assign($this->jakare, $instructor, OrganizationRole::Instructor);
    inPanel($this->admin, $this->jakare);
    $venue = Venue::factory()->for($this->jakare)->create();

    Livewire::test(CreateGroup::class)
        ->fillForm([
            'program_id' => $this->program->id,
            'name' => 'Sub-10',
            'min_age' => 9,
            'max_age' => 10,
            'instructors' => [$instructor->id],
            'schedules' => [
                ['weekday' => 1, 'starts_at' => '17:00', 'ends_at' => '18:30', 'venue_id' => $venue->id],
                ['weekday' => 3, 'starts_at' => '17:00', 'ends_at' => '18:30', 'venue_id' => null],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $group = Group::query()->where('name', 'Sub-10')->sole();
    expect($group->organization_id)->toBe($this->jakare->id)
        ->and($group->schedules)->toHaveCount(2)
        ->and($group->schedules->pluck('organization_id')->unique()->all())->toBe([$this->jakare->id])
        ->and($group->instructors->pluck('name')->all())->toBe(['Carlos Gómez']);
});

it('la ficha médica no aparece sin permiso', function () {
    $student = Student::factory()->for($this->jakare)->create();
    $secretary = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $secretary, OrganizationRole::Secretary, endsOn: now()->addYear());
    app(CurrentOrganization::class)->set($this->jakare);
    $permissions = collect(['ViewAny:Student', 'View:Student', 'Update:Student'])
        ->each(fn (string $name) => Permission::findOrCreate($name))->all();
    Role::query()->where('organization_id', $this->jakare->id)->where('name', 'secretario')->sole()
        ->givePermissionTo($permissions);

    $this->actingAs($secretary)
        ->get("/admin/jakare/alumnos/{$student->id}/edit")
        ->assertOk()
        ->assertDontSee('Ficha médica');

    $this->actingAs($this->admin)
        ->get("/admin/jakare/alumnos/{$student->id}/edit")
        ->assertOk()
        ->assertSee('Ficha médica');
});

it('no se puede abrir un alumno de otra organización', function () {
    $foreign = Student::factory()->for($this->ajena)->create();

    $this->actingAs($this->admin)
        ->get("/admin/jakare/alumnos/{$foreign->id}/edit")
        ->assertNotFound();
});

it('un tutor sin permisos no entra a alumnos', function () {
    $tutor = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $tutor, OrganizationRole::Guardian);

    $this->actingAs($tutor)->get('/admin/jakare/alumnos')->assertForbidden();
});

it('el importador procesa la fila en la organización de la opción', function () {
    Group::factory()->for($this->program)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id]);
    $import = Import::query()->create([
        'file_name' => 'jugadores.csv', 'file_path' => 'jugadores.csv', 'importer' => StudentImporter::class,
        'total_rows' => 1, 'user_id' => $this->admin->id,
    ]);

    StudentImporter::test(options: ['organization_id' => $this->jakare->id, 'invite' => true], import: $import)
        ->import([
            'first_name' => 'Mateo', 'last_name' => 'Benítez', 'document' => 6123456, 'birth_date' => '14/03/2016',
            'program' => 'Fútbol', 'group' => 'Sub-10', 'status' => 'activo',
            'guardian1_first_name' => 'Ana', 'guardian1_last_name' => 'Benítez', 'guardian1_email' => 'ana@test.com',
            'guardian1_relationship' => 'madre',
        ])
        ->assertImported();

    app(CurrentOrganization::class)->set($this->jakare);
    expect(Student::query()->sole()->document)->toBe('6123456')
        ->and(Enrollment::query()->count())->toBe(1);
    Mail::assertQueuedCount(1);

    StudentImporter::test(options: ['organization_id' => $this->jakare->id], import: $import)
        ->import(['first_name' => 'X', 'last_name' => 'Y', 'birth_date' => '01/01/2015', 'program' => 'Fútbol', 'group' => 'Sub-99'])
        ->assertHasRowFailure('No existe Categoría "Sub-99" en Fútbol.');
});

it('la lista de alumnos muestra la acción de importar', function () {
    inPanel($this->admin, $this->jakare);

    Livewire::test(ListStudents::class)->assertActionVisible('import');
});

it('las páginas del módulo académico cargan', function (string $path) {
    $student = Student::factory()->for($this->jakare)->create();
    Enrollment::factory()->create(['student_id' => $student->id]);
    $group = Group::query()->first();
    $student->guardians()->attach(Guardian::factory()->for($this->jakare)->create(), ['relationship' => 'madre']);

    $this->actingAs($this->admin)
        ->get(str_replace(['{student}', '{group}'], [$student->id, $group->id], "/admin/jakare/{$path}"))
        ->assertOk();
})->with([
    'grupos', 'grupos/create', 'grupos/{group}/edit', 'alumnos', 'alumnos/create',
    'alumnos/{student}/edit', 'tutores', 'inscripciones', 'temporadas',
]);

it('los relation managers del alumno muestran inscripciones y tutores', function () {
    inPanel($this->admin, $this->jakare);
    $student = Student::factory()->for($this->jakare)->create();
    $enrollment = Enrollment::factory()->create(['student_id' => $student->id]);
    $guardian = Guardian::factory()->for($this->jakare)->create(['first_name' => 'Ana', 'email' => 'ana@test.com']);
    $student->guardians()->attach($guardian, ['relationship' => 'madre']);

    Livewire::test(EnrollmentsRelationManager::class, ['ownerRecord' => $student, 'pageClass' => EditStudent::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$enrollment]);

    Livewire::test(GuardiansRelationManager::class, ['ownerRecord' => $student, 'pageClass' => EditStudent::class])
        ->assertOk()
        ->assertSee('Madre')
        ->callTableAction('invite', $guardian)
        ->assertActionMounted('showLink');

    Mail::assertQueued(InvitationMail::class, fn ($mail) => $mail->hasTo('ana@test.com'));
    expect($guardian->invitations()->sole()->guardian_id)->toBe($guardian->id);

    // "Reenviar invitación": la misma invitación con un link nuevo, sin sumar otra.
    $first = $guardian->invitations()->sole();
    Livewire::test(GuardiansRelationManager::class, ['ownerRecord' => $student, 'pageClass' => EditStudent::class])
        ->assertTableActionHasLabel('invite', 'Reenviar invitación', $guardian)
        ->callTableAction('invite', $guardian)
        ->assertActionMounted('showLink');

    expect($guardian->invitations()->sole())
        ->id->toBe($first->id)
        ->token_hash->not->toBe($first->token_hash);
});

it('un tutor con solo celular se invita por WhatsApp desde la ficha', function () {
    inPanel($this->admin, $this->jakare);
    $student = Student::factory()->for($this->jakare)->create();
    $guardian = Guardian::factory()->for($this->jakare)->create(['first_name' => 'Ana', 'email' => null, 'phone' => '0981 123 456']);
    $student->guardians()->attach($guardian, ['relationship' => 'madre']);

    Livewire::test(GuardiansRelationManager::class, ['ownerRecord' => $student, 'pageClass' => EditStudent::class])
        ->callTableAction('invite', $guardian)
        ->assertActionMounted('showLink')
        ->assertSet('mountedActions.0.arguments.phone', '+595981123456')
        ->assertSet('mountedActions.0.arguments.email', null);

    Mail::assertNothingQueued();
    expect($guardian->invitations()->sole())
        ->phone->toBe('+595981123456')
        ->guardian_id->toBe($guardian->id);
});

it('se inscribe solo desde la ficha del jugador y no permite duplicados', function () {
    inPanel($this->admin, $this->jakare);
    $student = Student::factory()->for($this->jakare)->create();
    $group = Group::factory()->for($this->program)->create(['name' => 'Sub-12', 'organization_id' => $this->jakare->id]);
    $manager = fn () => Livewire::test(EnrollmentsRelationManager::class, ['ownerRecord' => $student, 'pageClass' => EditStudent::class]);

    $manager()
        ->callTableAction('create', data: ['group_id' => $group->id])
        ->assertHasNoTableActionErrors();

    $enrollment = Enrollment::query()->sole();
    expect($enrollment->student_id)->toBe($student->id)
        ->and($enrollment->season_id)->toBe(Season::query()->orderBy('starts_on')->first()->id)
        ->and($enrollment->status)->toBe(EnrollmentStatus::Active);

    $manager()
        ->callTableAction('create', data: ['group_id' => $group->id])
        ->assertHasTableActionErrors(['group_id']);

    expect(Enrollment::query()->count())->toBe(1);
});

it('la lista de inscripciones no tiene alta y lleva a la ficha del jugador', function () {
    inPanel($this->admin, $this->jakare);
    $enrollment = Enrollment::factory()->create(['student_id' => Student::factory()->for($this->jakare)->create()->id]);

    expect(EnrollmentResource::canCreate())->toBeFalse();

    Livewire::test(ManageEnrollments::class)
        ->assertActionDoesNotExist('create')
        ->assertCanSeeTableRecords([$enrollment])
        ->callTableAction('changeStatus', $enrollment, data: ['status' => EnrollmentStatus::Withdrawn->value])
        ->assertHasNoTableActionErrors();

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Withdrawn)
        ->and($enrollment->fresh()->ended_on)->not->toBeNull();
});

it('los menús de familias, sedes y disciplinas ya no existen', function (string $path) {
    $this->actingAs($this->admin)->get("/admin/jakare/{$path}")->assertNotFound();
})->with(['familias', 'sedes', 'programas']);

it('nuevo jugador: datos, categoría sugerida y tutores en un paso (la invitación va desde la ficha)', function () {
    inPanel($this->admin, $this->jakare);
    $sub10 = Group::factory()->for($this->program)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id, 'min_age' => 9, 'max_age' => 10]);
    Group::factory()->for($this->program)->create(['name' => 'Sub-12', 'organization_id' => $this->jakare->id, 'min_age' => 11, 'max_age' => 12]);
    $year = Season::query()->orderBy('starts_on')->first()->starts_on->year;

    $page = Livewire::test(CreateStudent::class)
        ->fillForm(['first_name' => 'Mateo', 'last_name' => 'Benítez', 'document' => '6123456'])
        ->set('data.birth_date', ($year - 10).'-03-14')
        ->assertSchemaStateSet(['group_id' => $sub10->id, 'season_id' => Season::query()->orderBy('starts_on')->first()->id, 'status' => 'activo']);

    $guardians = array_keys($page->get('data.guardians'));
    $page->set("data.guardians.{$guardians[0]}", [
        'phone' => '0981 123 456', 'email' => 'ana@test.com', 'first_name' => 'Ana', 'last_name' => 'Benítez', 'relationship' => 'madre',
    ])
        ->assertDontSee('Enviar invitación a la app')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Jugador inscripto.');

    $student = Student::query()->where('document', '6123456')->sole();
    expect($student->currentEnrollments()->sole()->group_id)->toBe($sub10->id)
        ->and($student->guardians()->sole()->email)->toBe('ana@test.com')
        ->and($student->guardians()->sole()->phone)->toBe('+595981123456')
        ->and($student->family_id)->not->toBeNull();
    Mail::assertNothingQueued();
});

it('un menor sin tutores no se puede crear', function () {
    inPanel($this->admin, $this->jakare);
    $group = Group::factory()->for($this->program)->create(['organization_id' => $this->jakare->id]);

    Livewire::test(CreateStudent::class)
        ->fillForm([
            'first_name' => 'Mateo', 'last_name' => 'Benítez', 'birth_date' => now()->subYears(10)->toDateString(),
            'group_id' => $group->id, 'guardians' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['guardians' => 'min']);

    expect(Student::query()->count())->toBe(0);
});

it('un adulto se puede crear sin tutores', function () {
    inPanel($this->admin, $this->jakare);
    $group = Group::factory()->for($this->program)->create(['organization_id' => $this->jakare->id]);

    Livewire::test(CreateStudent::class)
        ->fillForm([
            'first_name' => 'Laura', 'last_name' => 'Ríos', 'birth_date' => now()->subYears(30)->toDateString(),
            'group_id' => $group->id, 'guardians' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Jugador inscripto.');

    expect(Student::query()->sole()->enrollments()->count())->toBe(1);
});

it('un correo de tutor ya cargado completa sus datos', function () {
    inPanel($this->admin, $this->jakare);
    $guardian = Guardian::factory()->for($this->jakare)->create(['first_name' => 'Ana', 'last_name' => 'Benítez', 'email' => 'ana@test.com', 'phone' => '0981 1']);

    $page = Livewire::test(CreateStudent::class);
    $item = array_key_first($page->get('data.guardians'));

    $page->set("data.guardians.{$item}.email", 'ANA@test.com')
        ->assertSet("data.guardians.{$item}.first_name", 'Ana')
        ->assertSet("data.guardians.{$item}.last_name", 'Benítez')
        ->assertSet("data.guardians.{$item}.phone", $guardian->phone);
});

it('un celular de tutor ya cargado completa sus datos', function () {
    inPanel($this->admin, $this->jakare);
    Guardian::factory()->for($this->jakare)->create(['first_name' => 'Ana', 'last_name' => 'Benítez', 'email' => 'ana@test.com', 'phone' => '0981 123 456']);

    $page = Livewire::test(CreateStudent::class);
    $item = array_key_first($page->get('data.guardians'));

    $page->set("data.guardians.{$item}.phone", '0981123456')
        ->assertSet("data.guardians.{$item}.first_name", 'Ana')
        ->assertSet("data.guardians.{$item}.email", 'ana@test.com')
        ->assertSee('Ya está cargado.');
});

it('con una sola disciplina no se pregunta en el formulario de categoría', function () {
    inPanel($this->admin, $this->jakare);

    Livewire::test(CreateGroup::class)
        ->assertFormFieldHidden('program_id')
        ->fillForm(['name' => 'Sub-8', 'min_age' => 7, 'max_age' => 8])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Group::query()->where('name', 'Sub-8')->sole()->program_id)->toBe($this->program->id);

    Program::factory()->for($this->jakare)->create(['name' => 'Pádel']);
    Livewire::test(CreateGroup::class)->assertFormFieldVisible('program_id');
});

it('vincular un tutor desde la ficha arma la familia', function () {
    inPanel($this->admin, $this->jakare);
    $brother = Student::factory()->for($this->jakare)->create();
    $guardian = Guardian::factory()->for($this->jakare)->create();
    $brother->guardians()->attach($guardian);
    $family = Family::syncFor($brother);
    $sister = Student::factory()->for($this->jakare)->create();

    Livewire::test(GuardiansRelationManager::class, ['ownerRecord' => $sister, 'pageClass' => EditStudent::class])
        ->callTableAction('attach', data: ['recordId' => $guardian->id, 'relationship' => 'madre'])
        ->assertHasNoTableActionErrors();

    expect($sister->fresh()->family_id)->toBe($family->id);
});
