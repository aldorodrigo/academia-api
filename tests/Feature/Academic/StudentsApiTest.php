<?php

use App\Enums\EnrollmentStatus;
use App\Enums\OrganizationRole;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\MedicalRecord;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Season;
use App\Models\Student;
use App\Models\User;
use App\Models\Venue;
use App\Policies\StudentPolicy;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    $this->ajena = Organization::factory()->create(['slug' => 'ajena']);

    $this->season = Season::factory()->for($this->jakare)->create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $this->program = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $this->group = Group::factory()->for($this->program)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id]);
    $venue = Venue::factory()->for($this->jakare)->create(['name' => 'Cancha 1']);
    Schedule::factory()->create(['group_id' => $this->group->id, 'weekday' => 3, 'starts_at' => '17:00', 'ends_at' => '18:30']);
    Schedule::factory()->create(['group_id' => $this->group->id, 'weekday' => 1, 'starts_at' => '17:00', 'ends_at' => '18:30', 'venue_id' => $venue->id]);

    $this->user = memberOf($this->jakare, ['name' => 'Ana Benítez']);
    $this->guardian = Guardian::factory()->for($this->jakare)->create([
        'first_name' => 'Ana', 'last_name' => 'Benítez', 'user_id' => $this->user->id,
    ]);

    $this->mateo = Student::factory()->for($this->jakare)->create([
        'first_name' => 'Mateo', 'last_name' => 'Benítez', 'birth_date' => '2016-03-14', 'document' => '6123456',
    ]);
    $this->mateo->guardians()->attach($this->guardian, ['relationship' => 'madre']);
    Enrollment::factory()->create([
        'student_id' => $this->mateo->id, 'group_id' => $this->group->id, 'season_id' => $this->season->id,
        'status' => EnrollmentStatus::Scholarship,
    ]);
    MedicalRecord::factory()->create(['student_id' => $this->mateo->id, 'blood_type' => 'O+', 'allergies' => 'Penicilina']);
});

function studentsApi(User $user, string $uri, string $organization = 'jakare')
{
    return test()->actingAs($user, 'sanctum')->getJson("/api/v1/{$uri}", ['X-Organization' => $organization]);
}

function assignRole(Organization $organization, User $user, OrganizationRole $role): void
{
    app(RoleAssigner::class)->assign(
        $organization,
        $user,
        Role::query()->where('organization_id', $organization->id)->where('name', $role->value)->firstOrFail(),
        endsOn: $role->isBoardPosition() ? now()->addYear() : null,
    );
}

it('lista solo los hijos del tutor con sus inscripciones vigentes', function () {
    Student::factory()->for($this->jakare)->create(['first_name' => 'Ajeno']);
    $old = Season::factory()->for($this->jakare)->create(['name' => '2025', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);
    Enrollment::factory()->create(['student_id' => $this->mateo->id, 'group_id' => $this->group->id, 'season_id' => $old->id]);

    studentsApi($this->user, 'students')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.full_name', 'Mateo Benítez')
        ->assertJsonPath('data.0.birth_date', '2016-03-14')
        ->assertJsonPath('data.0.is_self', false)
        ->assertJsonCount(1, 'data.0.enrollments')
        ->assertJsonPath('data.0.enrollments.0.status', 'becado')
        ->assertJsonPath('data.0.enrollments.0.status_label', 'Becado')
        ->assertJsonPath('data.0.enrollments.0.season.name', '2026')
        ->assertJsonPath('data.0.enrollments.0.group.name', 'Sub-10')
        ->assertJsonPath('data.0.enrollments.0.group.program.name', 'Fútbol')
        ->assertJsonMissingPath('data.0.medical');
});

it('el alumno adulto se ve a sí mismo', function () {
    $adult = memberOf($this->jakare);
    Student::factory()->for($this->jakare)->create(['user_id' => $adult->id, 'birth_date' => '1990-01-01']);

    studentsApi($adult, 'students')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_self', true);
});

it('la ficha trae horarios, instructores, tutores y ficha médica para el tutor', function () {
    $this->group->instructors()->attach(User::factory()->create(['name' => 'Carlos Gómez']));

    studentsApi($this->user, "students/{$this->mateo->id}")
        ->assertOk()
        ->assertJsonPath('data.document', '6123456')
        ->assertJsonPath('data.guardians.0.name', 'Ana Benítez')
        ->assertJsonPath('data.guardians.0.relationship', 'Madre')
        ->assertJsonPath('data.guardians.0.is_me', true)
        ->assertJsonPath('data.enrollments.0.group.schedules.0.weekday', 1)
        ->assertJsonPath('data.enrollments.0.group.schedules.0.starts_at', '17:00')
        ->assertJsonPath('data.enrollments.0.group.schedules.0.venue.name', 'Cancha 1')
        ->assertJsonPath('data.enrollments.0.group.schedules.1.venue', null)
        ->assertJsonPath('data.enrollments.0.group.instructors.0.name', 'Carlos Gómez')
        ->assertJsonPath('data.medical.blood_type', 'O+')
        ->assertJsonPath('data.medical.allergies', 'Penicilina')
        ->assertJsonPath('data.permissions.view_medical', true);
});

it('la ficha de un alumno ajeno responde 404 sin revelar que existe', function () {
    $other = memberOf($this->jakare);

    studentsApi($other, "students/{$this->mateo->id}")
        ->assertNotFound()
        ->assertJsonPath('message', 'No encontramos a este alumno.');
    studentsApi($other, 'students/999999')->assertNotFound();
});

it('no mezcla organizaciones', function () {
    $foreignUser = memberOf($this->ajena);
    $foreignGuardian = Guardian::factory()->for($this->ajena)->create(['user_id' => $foreignUser->id]);
    $foreignStudent = Student::factory()->for($this->ajena)->create();
    $foreignStudent->guardians()->attach($foreignGuardian);

    // El tutor de Jakare no ve alumnos de otra organización aunque pida su id.
    studentsApi($this->user, "students/{$foreignStudent->id}")->assertNotFound();

    // Ni siquiera los propios si usa la otra organización como activa.
    studentsApi($this->user, 'students', 'ajena')->assertForbidden();

    studentsApi($foreignUser, 'students', 'ajena')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $foreignStudent->id);
});

it('sin permiso la ficha médica no viaja', function () {
    // Un segundo tutor del mismo alumno sí la ve; otro miembro con permiso de ver alumnos no.
    $viewer = memberOf($this->jakare);
    $guardian = Guardian::factory()->for($this->jakare)->create(['user_id' => $viewer->id]);
    $this->mateo->guardians()->attach($guardian, ['relationship' => 'padre']);

    studentsApi($viewer, "students/{$this->mateo->id}")->assertJsonPath('data.permissions.view_medical', true);

    $policy = app(StudentPolicy::class);
    $stranger = memberOf($this->jakare);
    app(CurrentOrganization::class)->set($this->jakare);

    expect($policy->viewMedical($stranger, $this->mateo))->toBeFalse();
});

it('el instructor del grupo y los roles con permiso ven la ficha médica', function () {
    app(CurrentOrganization::class)->set($this->jakare);

    $instructor = memberOf($this->jakare);
    $this->group->instructors()->attach($instructor);
    $secretary = memberOf($this->jakare);
    assignRole($this->jakare, $secretary, OrganizationRole::Secretary);
    Permission::findOrCreate('ViewMedical:Student');
    Role::query()->where('organization_id', $this->jakare->id)->where('name', 'secretario')->first()
        ->givePermissionTo('ViewMedical:Student');

    expect($instructor->can('viewMedical', $this->mateo))->toBeTrue()
        ->and($secretary->fresh()->can('viewMedical', $this->mateo))->toBeTrue()
        ->and(memberOf($this->jakare)->can('viewMedical', $this->mateo))->toBeFalse();
});

it('medical es null si no se cargó la ficha', function () {
    $this->mateo->medicalRecord()->delete();

    studentsApi($this->user, "students/{$this->mateo->id}")
        ->assertJsonPath('data.medical', null)
        ->assertJsonPath('data.permissions.view_medical', true);
});

it('muestra las inscripciones de temporadas vigentes y próximas, con sus fechas', function () {
    $colonia = Season::factory()->for($this->jakare)->create(['name' => 'Colonia 2027', 'starts_on' => '2027-01-04', 'ends_on' => '2027-01-17']);
    $vieja = Season::factory()->for($this->jakare)->create(['name' => '2025', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);
    $colonia->programs()->attach($this->group->program_id);
    Enrollment::factory()->create(['student_id' => $this->mateo->id, 'group_id' => $this->group->id, 'season_id' => $colonia->id]);
    Enrollment::factory()->create(['student_id' => $this->mateo->id, 'group_id' => $this->group->id, 'season_id' => $vieja->id]);

    $enrollments = collect(studentsApi($this->user, "students/{$this->mateo->id}")->json('data.enrollments'));

    expect($enrollments->pluck('season.name')->all())->toEqualCanonicalizing(['2026', 'Colonia 2027'])
        ->and($enrollments->firstWhere('season.name', 'Colonia 2027')['season'])->toMatchArray([
            'starts_on' => '2027-01-04',
            'ends_on' => '2027-01-17',
            'programs' => [['id' => $this->group->program_id, 'name' => $this->group->program->name]],
        ]);
});

it('la ficha médica se guarda cifrada', function () {
    $raw = DB::table('medical_records')->where('student_id', $this->mateo->id)->value('allergies');

    expect($raw)->not->toContain('Penicilina');
});

it('grupo por edad: años de nacimiento de la temporada', function () {
    $group = Group::factory()->for($this->program)->create(['organization_id' => $this->jakare->id, 'min_age' => 9, 'max_age' => 10]);

    expect($group->birthYearsFor($this->season))->toBe([2016, 2017]);
});
