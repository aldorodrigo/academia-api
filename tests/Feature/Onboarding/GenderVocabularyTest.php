<?php

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Students\ImportStudentRow;
use App\Actions\Students\RegisterStudent;
use App\Enums\EnrollmentRequestStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\Gender;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\EnrollmentRequest;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Season;
use App\Models\User;
use App\Notifications\EnrollmentRequestReviewed;
use App\Support\Onboarding\Checklist;
use App\Support\Onboarding\Templates;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Mail;

/**
 * Concordancia con la palabra del club y con el género de la persona (docs/PLAN_GENERO.md).
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-10-05 10:00:00');
});

function genderOrg(OrganizationType $type = OrganizationType::Club, array $terminology = []): Organization
{
    return Organization::factory()->create([
        'slug' => 'org',
        'name' => 'La Organización',
        'type' => $type,
        'terminology' => array_merge(Templates::terminologyFor($type), $terminology),
    ]);
}

function genderApi(User $user, string $method, string $uri, array $data = [])
{
    return test()->actingAs($user, 'sanctum')->json($method, "/api/v1/{$uri}", $data, ['X-Organization' => 'org']);
}

describe('GET organization: el vocabulario con su género', function () {
    it('cada palabra con plural, género y artículo; las de persona con sus formas', function () {
        $organization = genderOrg(OrganizationType::School);
        $admin = memberOf($organization);

        genderApi($admin, 'GET', 'organization')->assertOk()
            ->assertJsonPath('data.vocabulary.group', ['word' => 'Grupo', 'plural' => 'Grupos', 'gender' => 'm', 'article' => 'el'])
            ->assertJsonPath('data.vocabulary.space', ['word' => 'Aula', 'plural' => 'Aulas', 'gender' => 'f', 'article' => 'el'])
            ->assertJsonPath('data.vocabulary.student.feminine', 'Alumna')
            ->assertJsonPath('data.vocabulary.student.feminine_plural', 'Alumnas')
            ->assertJsonPath('data.vocabulary.instructor.feminine', 'Profesora')
            ->assertJsonPath('data.vocabulary.organization', ['word' => 'escuela', 'plural' => 'escuelas', 'gender' => 'f', 'article' => 'la']);
    });

    it('la organización según su tipo (club, academia, escuela, comisión)', function (OrganizationType $type, string $word, string $article) {
        $organization = genderOrg($type);

        genderApi(memberOf($organization), 'GET', 'organization')
            ->assertJsonPath('data.vocabulary.organization.word', $word)
            ->assertJsonPath('data.vocabulary.organization.article', $article);
    })->with([
        [OrganizationType::Club, 'club', 'el'],
        [OrganizationType::Academy, 'academia', 'la'],
        [OrganizationType::School, 'escuela', 'la'],
        [OrganizationType::ParentsAssociation, 'comisión', 'la'],
    ]);

    it('"Atleta" es de género común; la organización ajusta la forma femenina si la regla no alcanza', function () {
        $organization = genderOrg(terminology: ['student' => 'Atleta', 'instructor' => 'Coach']);
        $admin = memberOf($organization);
        app(RoleAssigner::class)->assign($organization, $admin, OrganizationRole::Admin);

        genderApi($admin, 'GET', 'organization')
            ->assertJsonPath('data.vocabulary.student.gender', 'c')
            ->assertJsonPath('data.vocabulary.student.article', 'el')
            ->assertJsonPath('data.vocabulary.student.feminine', 'Atleta');

        genderApi($admin, 'PUT', 'organization/terminology', ['terminology' => [], 'feminine' => ['instructor' => 'entrenadora', 'student' => 'Atleta']])
            ->assertOk()
            ->assertJsonPath('data.terminology_feminine', ['instructor' => 'Entrenadora'])
            ->assertJsonPath('data.vocabulary.instructor.feminine', 'Entrenadora');

        expect($organization->refresh()->term('instructor', Gender::Female))->toBe('Entrenadora')
            ->and($organization->term('instructor', Gender::Male))->toBe('Coach')
            ->and($organization->term('instructor'))->toBe('Coach');
    });
});

describe('el género de la persona', function () {
    it('"Mi cuenta": la persona elige el suyo y sus perfiles la nombran así', function () {
        $organization = genderOrg();
        $laura = memberOf($organization);
        app(RoleAssigner::class)->assign($organization, $laura, OrganizationRole::Instructor);
        app(RoleAssigner::class)->assign($organization, $laura, OrganizationRole::Treasurer, endsOn: now()->addYear());

        genderApi($laura, 'GET', 'organization')->assertJsonPath('data.membership.roles.0.label', 'Técnico');

        genderApi($laura, 'PATCH', 'me', ['gender' => 'female'])->assertOk()->assertJsonPath('data.gender', 'female');
        $labels = genderApi($laura, 'GET', 'organization')->json('data.membership.roles.*.label');
        expect($labels)->toContain('Técnica')->toContain('Tesorera');

        genderApi($laura, 'PATCH', 'me', ['gender' => null])->assertOk()->assertJsonPath('data.gender', null);
        genderApi($laura, 'PATCH', 'me', ['gender' => 'otro'])->assertUnprocessable()->assertJsonValidationErrors('gender');
    });

    it('la invitación nombra a quien se invita: "Técnica" si se cargó; al aceptar pasa a su cuenta', function () {
        $organization = genderOrg();
        [$invitation, $token] = app(CreateInvitation::class)->handle($organization, 'lucia@test.com', [['role' => 'instructor']], gender: Gender::Female);

        $this->getJson("/api/v1/invitations/{$token}")->assertOk()
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.roles.0.label', 'Técnica');
        expect($invitation->roleLabels())->toBe(['Técnica']);

        $this->postJson("/api/v1/invitations/{$token}/accept", [
            'name' => 'Lucía Pérez', 'password' => 'secreta123', 'password_confirmation' => 'secreta123', 'device_name' => 'app', 'terms' => true,
        ])->assertCreated();

        expect(User::query()->where('email', 'lucia@test.com')->sole()->gender)->toBe(Gender::Female);
    });

    it('Primeros pasos (app): invitar a una técnica con su género', function () {
        $organization = genderOrg();
        $admin = memberOf($organization);
        app(RoleAssigner::class)->assign($organization, $admin, OrganizationRole::Admin);

        genderApi($admin, 'POST', 'setup/instructors', ['name' => 'Marta Ríos', 'email' => 'marta@test.com', 'gender' => 'female'])
            ->assertCreated()
            ->assertJsonPath('data.gender', 'female');
        genderApi($admin, 'POST', 'setup/instructors', ['name' => 'X', 'email' => 'x@test.com', 'gender' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('gender');

        expect(Invitation::query()->withoutGlobalScopes()->where('email', 'marta@test.com')->sole()->roleLabels())->toBe(['Técnica']);
    });

    it('a los tutores no se les pregunta: sale del parentesco (Madre → Tutora)', function () {
        $organization = genderOrg();
        $season = Season::factory()->for($organization)->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $group = Group::factory()->for(Program::factory()->for($organization)->create())->create(['organization_id' => $organization->id]);
        $student = app(RegisterStudent::class)->handle($organization, ['first_name' => 'Lucía', 'last_name' => 'Benítez', 'birth_date' => '2016-03-14'], $group, $season, EnrollmentStatus::Active, [
            ['first_name' => 'Laura', 'last_name' => 'Benítez', 'email' => 'laura@test.com', 'relationship' => 'madre'],
            ['first_name' => 'Pedro', 'last_name' => 'Benítez', 'email' => 'pedro@test.com', 'relationship' => 'tutor'],
        ]);

        $laura = app(CurrentOrganization::class)->run($organization, fn () => Guardian::query()->where('first_name', 'Laura')->sole());
        $pedro = app(CurrentOrganization::class)->run($organization, fn () => Guardian::query()->where('first_name', 'Pedro')->sole());
        [$invitation] = app(CreateInvitation::class)->forGuardian($laura);
        [$other] = app(CreateInvitation::class)->forGuardian($pedro);

        expect($laura->gender())->toBe(Gender::Female)
            ->and($pedro->gender())->toBeNull()
            ->and($invitation->roleLabels())->toBe(['Tutora'])
            ->and($other->roleLabels())->toBe(['Tutor'])
            ->and($student->gender)->toBeNull();
    });

    it('alumnos: se carga en el alta y en la importación (columna genero) y la API lo devuelve', function () {
        $organization = genderOrg();
        $season = Season::factory()->for($organization)->create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $program = Program::factory()->for($organization)->create(['name' => 'Fútbol']);
        Group::factory()->for($program)->create(['name' => 'Sub-10', 'organization_id' => $organization->id]);
        $tutor = memberOf($organization);

        $lucia = app(ImportStudentRow::class)->handle($organization, [
            'first_name' => 'Lucía', 'last_name' => 'Benítez', 'birth_date' => '14/03/2016', 'gender' => 'F',
            'program' => 'Fútbol', 'group' => 'Sub-10',
            'guardians' => [['first_name' => 'Laura', 'email' => $tutor->email, 'relationship' => 'madre']],
        ]);
        $mateo = app(ImportStudentRow::class)->handle($organization, [
            'first_name' => 'Mateo', 'last_name' => 'Benítez', 'birth_date' => '14/03/2017', 'gender' => '',
            'program' => 'Fútbol', 'group' => 'Sub-10',
            'guardians' => [['first_name' => 'Laura', 'email' => $tutor->email, 'relationship' => 'madre']],
        ]);

        expect($lucia->gender)->toBe(Gender::Female)->and($mateo->gender)->toBeNull();

        app(CurrentOrganization::class)->run($organization, fn () => Guardian::query()->sole()->update(['user_id' => $tutor->id]));
        genderApi($tutor, 'GET', "students/{$lucia->id}")->assertOk()->assertJsonPath('data.gender', 'female');
    });

    it('Primeros pasos: "1 técnica", "2 técnicos"', function () {
        $organization = genderOrg();
        $ana = memberOf($organization, ['gender' => Gender::Female]);
        app(RoleAssigner::class)->assign($organization, $ana, OrganizationRole::Instructor);

        $summary = fn () => collect(app(CurrentOrganization::class)->run($organization, fn () => (new Checklist($organization->fresh()))->toArray()['steps']))
            ->firstWhere('key', 'instructors');

        Group::factory()->for(Program::factory()->for($organization)->create())->create(['organization_id' => $organization->id]);
        expect($summary()['description'])->toBe('Invitalos para que tomen asistencia desde la app.');

        // El resumen solo aparece con el paso hecho.
        expect($summary()['summary'])->toBe('1 técnica');

        app(RoleAssigner::class)->assign($organization, memberOf($organization, ['gender' => Gender::Male]), OrganizationRole::Instructor);
        expect($summary()['summary'])->toBe('2 técnicos');
    });
});

describe('textos con el tipo de organización', function () {
    it('"la academia", "el club"…', function (OrganizationType $type, string $expected) {
        $organization = genderOrg($type);
        $user = memberOf($organization);

        // Sin permiso de configurar: el mensaje nombra a la organización.
        genderApi($user, 'PUT', 'organization/terminology', ['terminology' => []])
            ->assertForbidden()
            ->assertJsonPath('message', "Solo los administradores configuran {$expected}.");
    })->with([
        [OrganizationType::Club, 'el club'],
        [OrganizationType::Academy, 'la academia'],
        [OrganizationType::School, 'la escuela'],
        [OrganizationType::ParentsAssociation, 'la comisión'],
    ]);

    it('la solicitud rechazada dice quién la rechazó', function (OrganizationType $type, string $expected) {
        $organization = genderOrg($type);
        $season = Season::factory()->for($organization)->create(['name' => '2026']);
        $group = Group::factory()->for(Program::factory()->for($organization)->create(['name' => 'Danza']))->create(['name' => 'Inicial', 'organization_id' => $organization->id]);
        $request = new EnrollmentRequest(['first_name' => 'Lucía', 'rejection_reason' => 'No hay lugar']);
        $request->status = EnrollmentRequestStatus::Rejected;
        $request->setRelation('organization', $organization)->setRelation('group', $group)->setRelation('season', $season);

        expect((new EnrollmentRequestReviewed($request))->body)->toBe("{$expected} la inscripción de Lucía: No hay lugar");
    })->with([
        [OrganizationType::Club, 'El club no aprobó'],
        [OrganizationType::Academy, 'La academia no aprobó'],
        [OrganizationType::School, 'La escuela no aprobó'],
        [OrganizationType::ParentsAssociation, 'La comisión no aprobó'],
    ]);
});
