<?php

use App\Actions\Invitations\AcceptInvitation;
use App\Enums\OrganizationRole;
use App\Mail\InvitationMail;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Models\User;
use App\Models\Venue;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo('2026-10-03 10:00:00');
    Mail::fake();
    $this->club = Organization::factory()->create(['slug' => 'ritmo', 'name' => 'Academia Ritmo']);
    $this->ajena = Organization::factory()->create(['slug' => 'ajena']);
    $this->admin = memberOf($this->club, ['name' => 'Laura Gómez', 'email' => 'laura@test.com']);
    app(RoleAssigner::class)->assign($this->club, $this->admin, OrganizationRole::Admin);
});

function setupApi(string $method, string $uri, array $data = [], ?User $user = null, string $organization = 'ritmo')
{
    return test()->actingAs($user ?? test()->admin, 'sanctum')
        ->json($method, "/api/v1/{$uri}", $data, ['X-Organization' => $organization]);
}

/** @return array<string, string> clave → estado */
function stepStatuses(): array
{
    return collect(setupApi('GET', 'onboarding')->assertOk()->json('data.steps'))
        ->mapWithKeys(fn (array $step) => [$step['key'] => $step['status']])->all();
}

function inClub(callable $callback): mixed
{
    return app(CurrentOrganization::class)->run(test()->club, $callback);
}

describe('checklist', function () {
    it('arranca con disciplinas y deja el resto bloqueado', function () {
        $response = setupApi('GET', 'onboarding')->assertOk();

        expect(stepStatuses())->toBe([
            'programs' => 'pending',
            'groups' => 'locked',
            'season' => 'locked',
            'instructors' => 'locked',
        ]);
        $response->assertJsonPath('data.next', 'programs')
            ->assertJsonPath('data.done', 0)
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.completed', false)
            ->assertJsonPath('data.dismissed', false)
            ->assertJsonPath('data.steps.1.blocked_by', 'programs')
            ->assertJsonPath('data.steps.3.blocked_by', 'groups');
    });

    it('los pasos salen de los datos aunque se carguen fuera de la guía', function () {
        inClub(function () {
            $futbol = Program::factory()->for(test()->club)->create(['name' => 'Fútbol']);
            $group = Group::factory()->for($futbol)->create(['organization_id' => test()->club->id, 'name' => 'Sub-10']);
            Schedule::query()->create(['group_id' => $group->id, 'weekday' => 2, 'starts_at' => '17:00', 'ends_at' => '18:30']);
            Season::query()->create(['name' => '2027', 'kind' => 'anual', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
        });

        $response = setupApi('GET', 'onboarding');

        expect(stepStatuses())->toBe([
            'programs' => 'done',
            'groups' => 'done',
            'season' => 'done',
            'instructors' => 'pending',
        ]);
        $response->assertJsonPath('data.steps.0.summary', 'Fútbol')
            ->assertJsonPath('data.steps.1.summary', '1 categoría')
            ->assertJsonPath('data.steps.2.summary', '2027 · sin cuotas')
            ->assertJsonPath('data.next', 'instructors');
    });

    it('omitir un paso lo cuenta como hecho y completa la guía', function () {
        inClub(function () {
            $futbol = Program::factory()->for(test()->club)->create();
            $group = Group::factory()->for($futbol)->create(['organization_id' => test()->club->id]);
            Schedule::query()->create(['group_id' => $group->id, 'weekday' => 2, 'starts_at' => '17:00', 'ends_at' => '18:30']);
            Season::query()->create(['name' => '2027', 'kind' => 'anual', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
        });

        setupApi('PUT', 'onboarding/steps/instructors', ['skipped' => true])
            ->assertOk()
            ->assertJsonPath('data.steps.3.status', 'skipped')
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.next', null);

        expect($this->club->fresh()->onboarding_completed_at)->not->toBeNull();

        setupApi('PUT', 'onboarding/steps/programs', ['skipped' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.key.0', 'Este paso no se puede dejar para después.');
    });

    it('cerrar y volver a abrir la guía', function () {
        setupApi('PUT', 'onboarding', ['dismissed' => true])->assertJsonPath('data.dismissed', true);
        expect($this->club->fresh()->onboarding_dismissed_at)->not->toBeNull();

        setupApi('PUT', 'onboarding', ['dismissed' => false])->assertJsonPath('data.dismissed', false);
    });

    it('usa el vocabulario del club', function () {
        $this->club->update(['terminology' => ['group' => 'Nivel', 'instructor' => 'Profesora']]);

        setupApi('GET', 'onboarding')
            ->assertJsonPath('data.steps.1.title', 'Niveles y horarios')
            ->assertJsonPath('data.steps.3.title', 'Profesoras');
    });

    it('las plantillas no dependen de la organización', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/onboarding/templates')
            ->assertOk()
            ->assertJsonPath('data.organization_types.0.value', 'club')
            ->assertJsonPath('data.organization_types.1.terminology.instructor', 'Profesor')
            ->assertJsonPath('data.programs.0', ['name' => 'Fútbol', 'group_criterion' => 'birth_year'])
            ->assertJsonPath('data.ages', ['from' => 5, 'to' => 16, 'span' => 2]);
    });
});

describe('disciplinas', function () {
    it('crea las elegidas sin duplicar', function () {
        inClub(fn () => Program::factory()->for(test()->club)->create(['name' => 'Fútbol']));

        setupApi('POST', 'setup/programs', ['programs' => [
            ['name' => 'fútbol', 'group_criterion' => 'birth_year'],
            ['name' => 'Danza', 'group_criterion' => 'level'],
        ]])->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Danza')
            ->assertJsonPath('data.0.group_criterion', 'level');
    });

    it('no se borra una disciplina con categorías', function () {
        $futbol = inClub(fn () => Program::factory()->for(test()->club)->create());
        inClub(fn () => Group::factory()->for($futbol)->create(['organization_id' => test()->club->id]));

        setupApi('DELETE', "setup/programs/{$futbol->id}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.program.0', 'Tiene categorías: borralas primero.');
    });

    it('no se ven las de otra organización', function () {
        $ajena = Program::factory()->for($this->ajena)->create();

        setupApi('GET', 'setup/programs')->assertJsonCount(0, 'data');
        setupApi('PUT', "setup/programs/{$ajena->id}", ['name' => 'X', 'group_criterion' => 'level'])->assertNotFound();
    });
});

describe('categorías', function () {
    beforeEach(function () {
        $this->futbol = inClub(fn () => Program::factory()->for(test()->club)->create(['name' => 'Fútbol', 'group_criterion' => 'birth_year']));
        $this->danza = inClub(fn () => Program::factory()->for(test()->club)->create(['name' => 'Danza', 'group_criterion' => 'level']));
    });

    it('sugiere por edad alineado con la más grande, sin las que ya existen', function () {
        inClub(fn () => Group::factory()->for($this->futbol)->create(['organization_id' => test()->club->id, 'name' => 'Sub-10']));

        $names = collect(setupApi('POST', 'setup/groups/suggestions', [
            'program_id' => $this->futbol->id,
            'ages' => ['from' => 5, 'to' => 16, 'span' => 2],
        ])->assertOk()->json('data'))->pluck('name')->all();

        expect($names)->toBe(['Sub-6', 'Sub-8', 'Sub-12', 'Sub-14', 'Sub-16']);

        setupApi('POST', 'setup/groups/suggestions', ['program_id' => $this->futbol->id, 'ages' => ['from' => 5, 'to' => 7, 'span' => 2]])
            ->assertJsonPath('data.0', ['name' => 'Sub-5', 'min_age' => 5, 'max_age' => 5, 'level' => null])
            ->assertJsonPath('data.1', ['name' => 'Sub-7', 'min_age' => 6, 'max_age' => 7, 'level' => null]);
    });

    it('sugiere por nivel', function () {
        setupApi('POST', 'setup/groups/suggestions', ['program_id' => $this->danza->id, 'levels' => ['Inicial', 'inicial', 'Avanzado']])
            ->assertJsonPath('data', [
                ['name' => 'Inicial', 'min_age' => null, 'max_age' => null, 'level' => 'Inicial'],
                ['name' => 'Avanzado', 'min_age' => null, 'max_age' => null, 'level' => 'Avanzado'],
            ]);
    });

    it('crea en lote con horarios y un lugar nuevo', function () {
        setupApi('POST', 'setup/groups', [
            'program_id' => $this->futbol->id,
            'groups' => [
                ['name' => 'Sub-8', 'min_age' => 7, 'max_age' => 8, 'capacity' => 20, 'schedules' => [
                    ['weekday' => 2, 'starts_at' => '17:00', 'ends_at' => '18:30'],
                    ['weekday' => 4, 'starts_at' => '17:00', 'ends_at' => '18:30'],
                ]],
                ['name' => 'Sub-10', 'min_age' => 9, 'max_age' => 10, 'schedules' => [
                    ['weekday' => 6, 'starts_at' => '09:00', 'ends_at' => '10:30'],
                ]],
            ],
            'venue' => ['name' => 'Polideportivo', 'address' => 'Av. España 123'],
        ])->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.capacity', 20)
            ->assertJsonPath('data.0.schedules.1', ['weekday' => 4, 'starts_at' => '17:00', 'ends_at' => '18:30', 'venue' => [
                'id' => inClub(fn () => Venue::query()->where('name', 'Polideportivo')->value('id')),
                'name' => 'Polideportivo',
            ]]);

        expect(stepStatuses()['groups'])->toBe('done');
    });

    it('no repite nombres ni acepta horarios al revés', function () {
        inClub(fn () => Group::factory()->for($this->futbol)->create(['organization_id' => test()->club->id, 'name' => 'Sub-8']));

        setupApi('POST', 'setup/groups', [
            'program_id' => $this->futbol->id,
            'groups' => [['name' => 'sub-8', 'schedules' => []]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['groups.0.name' => 'Ya existe «sub-8» en Fútbol.']);

        setupApi('POST', 'setup/groups', [
            'program_id' => $this->futbol->id,
            'groups' => [['name' => 'Sub-12', 'schedules' => [['weekday' => 1, 'starts_at' => '18:00', 'ends_at' => '17:00']]]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['groups.0.schedules.0.ends_at' => 'El horario tiene que terminar después de empezar.']);
    });

    it('cambia el horario y no borra una categoría con inscripciones', function () {
        $group = inClub(fn () => Group::factory()->for($this->futbol)->create(['organization_id' => test()->club->id, 'name' => 'Sub-8']));

        setupApi('PUT', "setup/groups/{$group->id}", [
            'name' => 'Sub-8',
            'schedules' => [['weekday' => 3, 'starts_at' => '18:00', 'ends_at' => '19:00']],
        ])->assertOk()->assertJsonPath('data.schedules.0.weekday', 3);

        inClub(function () use ($group) {
            $season = Season::query()->create(['name' => '2026', 'kind' => 'anual', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
            Enrollment::query()->create([
                'student_id' => Student::factory()->for(test()->club)->create()->id,
                'group_id' => $group->id,
                'season_id' => $season->id,
                'status' => 'activo',
                'enrolled_on' => '2026-10-01',
            ]);
        });

        setupApi('DELETE', "setup/groups/{$group->id}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.group.0', 'Tiene inscripciones: desactivala en vez de borrarla.');
    });
});

describe('temporada', function () {
    beforeEach(function () {
        $this->futbol = inClub(fn () => Program::factory()->for(test()->club)->create(['name' => 'Fútbol']));
    });

    it('sugiere el estado inicial y calcula fechas, resumen y cuotas', function () {
        $draft = setupApi('GET', 'setup/seasons/new')->assertOk()->json('data');

        expect($draft)->toMatchArray([
            'program_ids' => [$this->futbol->id],
            'kind' => 'anual',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'name' => '2027',
            'fee_frequency' => 'mensual',
            'due_days' => 9,
            'issue_upfront' => false,
        ]);

        setupApi('POST', 'setup/seasons/preview', [...$draft, 'kind' => 'semestral', 'fee_amount' => 150000])
            ->assertOk()
            ->assertJsonPath('data.dates', ['ends_on' => '2027-06-30', 'name' => '1.er semestre 2027'])
            ->assertJsonPath('data.plan.due_days_by_frequency.semanal', 3)
            ->assertJsonPath('data.examples.0', ['period' => 'enero 2027', 'due_on' => '10/01/2027', 'amount' => '₲ 150.000'])
            ->assertJsonPath('data.periods_count', 12)
            ->assertJsonPath('data.summary', fn (string $summary) => str_contains($summary, 'Cuota mensual de ₲ 150.000'));
    });

    it('crea la temporada con sus montos, como el panel', function () {
        $sub10 = inClub(fn () => Group::factory()->for($this->futbol)->create(['organization_id' => test()->club->id, 'name' => 'Sub-10']));

        setupApi('POST', 'setup/seasons', [
            'program_ids' => [],
            'kind' => 'anual',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'name' => '2027',
            'fee_frequency' => 'mensual',
            'fee_amount' => 150000,
            'enrollment_fee_amount' => 100000,
            'group_amounts' => [['group_id' => $sub10->id, 'amount' => 180000]],
            'due_days' => 9,
            'issue_upfront' => false,
            'mid_period' => 'completo',
        ])->assertCreated()
            ->assertJsonPath('data.name', '2027')
            ->assertJsonPath('data.status', 'proxima')
            ->assertJsonPath('data.has_fee_plan', true);

        $season = inClub(fn () => Season::query()->where('name', '2027')->firstOrFail());
        expect($season->programs()->pluck('programs.id')->all())->toBe([$this->futbol->id])
            ->and(inClub(fn () => Tariff::query()->where('season_id', $season->id)->orderBy('amount')->pluck('amount')->all()))
            ->toBe([100000, 150000, 180000]);
    });

    it('sin plan de cobro no pide montos', function () {
        setupApi('POST', 'setup/seasons', [
            'kind' => 'mensual', 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31', 'name' => 'Colonia',
            'fee_frequency' => null,
        ])->assertCreated()->assertJsonPath('data.has_fee_plan', false);
    });

    it('valida como el asistente', function () {
        inClub(fn () => Program::factory()->for(test()->club)->create(['name' => 'Danza']));

        setupApi('POST', 'setup/seasons', [
            'kind' => 'anual', 'starts_on' => '2027-01-01', 'ends_on' => '2026-12-31', 'name' => '2027',
            'fee_frequency' => 'mensual', 'due_days' => 9, 'mid_period' => 'completo',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.ends_on.0', 'Tiene que terminar después de empezar.')
            ->assertJsonPath('errors.program_ids.0', 'Elegí al menos una disciplina.')
            ->assertJsonPath('errors.fee_amount.0', 'Ingresá el monto de la cuota.');
    });
});

describe('técnicos', function () {
    beforeEach(function () {
        $futbol = inClub(fn () => Program::factory()->for(test()->club)->create());
        $this->sub8 = inClub(fn () => Group::factory()->for($futbol)->create(['organization_id' => test()->club->id, 'name' => 'Sub-8']));
        $this->sub10 = inClub(fn () => Group::factory()->for($futbol)->create(['organization_id' => test()->club->id, 'name' => 'Sub-10']));
    });

    it('invita con sus categorías y al aceptar quedan asignadas', function () {
        $response = setupApi('POST', 'setup/instructors', [
            'name' => 'Marta Ríos', 'email' => 'Marta@Test.com', 'group_ids' => [$this->sub10->id],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'invitado')
            ->assertJsonPath('data.name', 'Marta Ríos')
            ->assertJsonPath('data.groups', [['id' => $this->sub10->id, 'name' => 'Sub-10']]);

        expect($response->json('data.link'))->toContain('/invitacion/');
        Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('marta@test.com'));

        // La invitación muestra el nombre que cargó el admin.
        $token = str($response->json('data.link'))->afterLast('/')->toString();
        $this->getJson("/api/v1/invitations/{$token}")->assertJsonPath('data.name', 'Marta Ríos');

        [$marta] = app(AcceptInvitation::class)->handle(Invitation::findByToken($token), [
            'name' => 'Marta Ríos', 'password' => 'secreta123', 'device_name' => 'app',
        ]);

        expect($marta->hasCurrentRole($this->club, OrganizationRole::Instructor))->toBeTrue()
            ->and($marta->instructedGroups()->pluck('groups.id')->all())->toBe([$this->sub10->id]);

        setupApi('GET', 'setup/instructors')
            ->assertJsonPath('data.instructors.0.status', 'activo')
            ->assertJsonPath('data.instructors.0.user_id', $marta->id);
        expect(stepStatuses()['instructors'])->toBe('done');
    });

    it('invita por celular: link para WhatsApp a ese número, sin correo', function () {
        $response = setupApi('POST', 'setup/instructors', [
            'name' => 'Marta Ríos', 'phone' => '0981 555 444', 'group_ids' => [$this->sub8->id],
        ])->assertCreated()
            ->assertJsonPath('data.phone', '+595981555444')
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.status', 'invitado');

        expect($response->json('data.whatsapp_url'))->toStartWith('https://wa.me/595981555444?text=Hola%20Marta');
        Mail::assertNothingQueued();

        setupApi('GET', 'setup/instructors')->assertJsonPath('data.instructors.0.phone', '+595981555444');

        $id = $response->json('data.invitation_id');
        expect(setupApi('POST', "setup/invitations/{$id}/resend")->json('data.whatsapp_url'))
            ->toStartWith('https://wa.me/595981555444');

        setupApi('POST', 'setup/instructors', ['name' => 'Nadie'])
            ->assertJsonPath('errors.phone.0', 'Ingresá el celular o el correo.');
    });

    it('lista los invitados y no se invita a sí mismo', function () {
        setupApi('POST', 'setup/instructors', ['name' => 'Marta', 'email' => 'marta@test.com']);

        setupApi('GET', 'setup/instructors')
            ->assertJsonPath('data.me', ['teaches' => false, 'group_ids' => []])
            ->assertJsonPath('data.instructors.0.status', 'invitado')
            ->assertJsonPath('data.instructors.0.name', 'Marta');

        setupApi('POST', 'setup/instructors', ['name' => 'Yo', 'email' => 'LAURA@test.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Para vos, usá «Yo también doy clases».');
    });

    it('el admin también da clases', function () {
        setupApi('PUT', 'setup/instructors/me', ['teaches' => true, 'group_ids' => [$this->sub8->id]])
            ->assertOk()
            ->assertJsonPath('data.me', ['teaches' => true, 'group_ids' => [$this->sub8->id]]);

        expect($this->admin->hasCurrentRole($this->club, OrganizationRole::Instructor))->toBeTrue();
        // Ya toma asistencia desde la app.
        expect(setupApi('GET', 'organization')->json('data.membership.permissions'))->toContain('take_attendance');

        setupApi('PUT', 'setup/instructors/me', ['teaches' => false])
            ->assertJsonPath('data.me', ['teaches' => false, 'group_ids' => []]);
        expect($this->admin->fresh()->hasCurrentRole($this->club, OrganizationRole::Instructor))->toBeFalse()
            ->and($this->admin->instructedGroups()->count())->toBe(0);
    });

    it('reenviar da un link nuevo y borrar revoca', function () {
        $id = setupApi('POST', 'setup/instructors', ['name' => 'Marta', 'email' => 'marta@test.com', 'group_ids' => [$this->sub8->id]])
            ->json('data.invitation_id');

        $link = setupApi('POST', "setup/invitations/{$id}/resend")->assertOk()->json('data.link');
        $nueva = Invitation::findByToken(str($link)->afterLast('/')->toString());
        expect($nueva->group_ids)->toBe([$this->sub8->id])->and($nueva->name)->toBe('Marta');

        setupApi('DELETE', "setup/invitations/{$nueva->id}")->assertNoContent();
        setupApi('GET', 'setup/instructors')->assertJsonCount(0, 'data.instructors');
    });
});
