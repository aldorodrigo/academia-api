<?php

use App\Actions\Attendance\SendClassReminders;
use App\Actions\Billing\GenerateSeasonCharges;
use App\Enums\EnrollmentStatus;
use App\Enums\GuardianResponse;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\RelationManagers\ClassSessionsRelationManager;
use App\Models\Attendance;
use App\Models\Charge;
use App\Models\ClassReminderPreference;
use App\Models\ClassSession;
use App\Models\DeviceToken;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\ClassReminder;
use App\Notifications\ClassSuspended;
use App\Support\Push\PushChannel;
use App\Support\Push\PushMessage;
use App\Support\Push\PushSender;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    // Lunes 28/09/2026 a las 10:00 en Asunción.
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Asuncion'));

    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    $this->ajena = Organization::factory()->create(['slug' => 'ajena']);

    $this->season = Season::factory()->for($this->jakare)->create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $this->futbol = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $this->sub10 = Group::factory()->for($this->futbol)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id]);
    $this->sub12 = Group::factory()->for($this->futbol)->create(['name' => 'Sub-12', 'organization_id' => $this->jakare->id]);
    $venue = Venue::factory()->for($this->jakare)->create(['name' => 'Cancha 1']);
    // Sub-10: lunes y miércoles; Sub-12: lunes.
    foreach ([1, 3] as $weekday) {
        Schedule::factory()->create(['group_id' => $this->sub10->id, 'weekday' => $weekday, 'starts_at' => '17:00', 'ends_at' => '18:30', 'venue_id' => $venue->id]);
    }
    Schedule::factory()->create(['group_id' => $this->sub12->id, 'weekday' => 1, 'starts_at' => '18:30', 'ends_at' => '20:00']);

    $this->instructor = memberOf($this->jakare, ['name' => 'Carlos Gómez']);
    app(RoleAssigner::class)->assign(
        $this->jakare,
        $this->instructor,
        Role::query()->where('organization_id', $this->jakare->id)->where('name', OrganizationRole::Instructor->value)->firstOrFail(),
    );
    $this->sub10->instructors()->attach($this->instructor);

    $this->tutor = memberOf($this->jakare, ['name' => 'Ana Benítez']);
    $guardian = Guardian::factory()->for($this->jakare)->create(['first_name' => 'Ana', 'last_name' => 'Benítez', 'user_id' => $this->tutor->id]);

    $this->mateo = attendanceStudent('Mateo', 'Benítez', $this->sub10);
    $this->mateo->guardians()->attach($guardian, ['relationship' => 'madre']);
    $this->lucas = attendanceStudent('Lucas', 'Aquino', $this->sub10);
    attendanceStudent('Diego', 'Ortiz', $this->sub12);
});

function attendanceStudent(string $first, string $last, Group $group, EnrollmentStatus $status = EnrollmentStatus::Active): Student
{
    $student = Student::factory()->for(test()->jakare)->create(['first_name' => $first, 'last_name' => $last]);
    Enrollment::factory()->create(['student_id' => $student->id, 'group_id' => $group->id, 'season_id' => test()->season->id, 'status' => $status]);

    return $student;
}

function attendanceApi(User $user, string $method, string $uri, array $data = [], string $organization = 'jakare')
{
    return test()->actingAs($user, 'sanctum')->json($method, "/api/v1/{$uri}", $data, ['X-Organization' => $organization]);
}

function todayClassId(User $user): int
{
    return attendanceApi($user, 'GET', 'classes')->json('data.0.id');
}

describe('técnico', function () {
    it('ve las clases de hoy de sus grupos y el permiso en la organización', function () {
        attendanceApi($this->instructor, 'GET', 'organization')
            ->assertJsonPath('data.membership.permissions', ['take_attendance']);

        attendanceApi($this->instructor, 'GET', 'classes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-09-28')
            ->assertJsonPath('data.0.starts_at', '17:00')
            ->assertJsonPath('data.0.ends_at', '18:30')
            ->assertJsonPath('data.0.venue.name', 'Cancha 1')
            ->assertJsonPath('data.0.group.name', 'Sub-10')
            ->assertJsonPath('data.0.group.program.name', 'Fútbol')
            ->assertJsonPath('data.0.status', 'programada')
            ->assertJsonPath('data.0.attendance_taken', false)
            ->assertJsonPath('data.0.counts', ['enrolled' => 2, 'going' => 0, 'not_going' => 0, 'no_answer' => 2, 'present' => 0, 'absent' => 0, 'justified' => 0]);

        // Idempotente: no duplica la clase.
        attendanceApi($this->instructor, 'GET', 'classes')->assertJsonCount(1, 'data');
        expect(ClassSession::query()->withoutGlobalScopes()->count())->toBe(1);
    });

    it('sin clases el martes y sin temporada no hay clases', function () {
        attendanceApi($this->instructor, 'GET', 'classes?date=2026-09-29')->assertJsonCount(0, 'data');
        attendanceApi($this->instructor, 'GET', 'classes?date=2027-01-04')->assertJsonCount(0, 'data');
    });

    it('el tutor no tiene permiso ni clases, y no ve clases ajenas', function () {
        attendanceApi($this->tutor, 'GET', 'organization')->assertJsonPath('data.membership.permissions', []);
        attendanceApi($this->tutor, 'GET', 'classes')->assertJsonCount(0, 'data');

        $id = todayClassId($this->instructor);
        attendanceApi($this->tutor, 'GET', "classes/{$id}")->assertNotFound();
    });

    it('otra organización no ve la clase', function () {
        $id = todayClassId($this->instructor);
        $foreign = memberOf($this->ajena);
        $foreign->instructedGroups()->attach($this->sub10);

        attendanceApi($foreign, 'GET', "classes/{$id}", organization: 'ajena')->assertNotFound();
    });

    it('detalle con los alumnos por apellido y la respuesta del tutor', function () {
        $id = todayClassId($this->instructor);
        attendanceApi($this->tutor, 'PUT', "classes/{$id}/students/{$this->mateo->id}/response", ['going' => false])->assertOk();

        attendanceApi($this->instructor, 'GET', "classes/{$id}")
            ->assertOk()
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.students.0.full_name', 'Lucas Aquino')
            ->assertJsonPath('data.students.1.full_name', 'Mateo Benítez')
            ->assertJsonPath('data.students.1.guardian_response', 'no_va')
            ->assertJsonPath('data.students.1.status', null)
            ->assertJsonPath('data.counts.not_going', 1)
            ->assertJsonPath('data.counts.no_answer', 1);
    });

    it('guarda la asistencia de una vez y la puede corregir', function () {
        $id = todayClassId($this->instructor);

        attendanceApi($this->instructor, 'PUT', "classes/{$id}/attendance", ['marks' => [
            ['student_id' => $this->mateo->id, 'status' => 'justificado', 'note' => 'Avisó que no va'],
            ['student_id' => $this->lucas->id, 'status' => 'presente'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.attendance_taken', true)
            ->assertJsonPath('data.counts.present', 1)
            ->assertJsonPath('data.counts.justified', 1)
            ->assertJsonPath('data.students.1.note', 'Avisó que no va');

        attendanceApi($this->instructor, 'PUT', "classes/{$id}/attendance", ['marks' => [
            ['student_id' => $this->lucas->id, 'status' => 'ausente'],
        ]])->assertJsonPath('data.counts.absent', 1)->assertJsonPath('data.counts.present', 0);

        expect(Attendance::query()->withoutGlobalScopes()->count())->toBe(2);
    });

    it('rechaza alumnos ajenos y clases viejas', function () {
        $id = todayClassId($this->instructor);
        $diego = Student::query()->where('first_name', 'Diego')->sole();

        attendanceApi($this->instructor, 'PUT', "classes/{$id}/attendance", ['marks' => [
            ['student_id' => $diego->id, 'status' => 'presente'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('marks');

        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'America/Asuncion'));
        attendanceApi($this->instructor, 'GET', "classes/{$id}")->assertJsonPath('data.editable', false);
        attendanceApi($this->instructor, 'PUT', "classes/{$id}/attendance", ['marks' => [
            ['student_id' => $this->lucas->id, 'status' => 'presente'],
        ]])->assertUnprocessable();
    });

    it('suspende la clase y avisa a los tutores', function () {
        Notification::fake();
        $id = todayClassId($this->instructor);

        attendanceApi($this->instructor, 'POST', "classes/{$id}/suspension", ['reason' => 'Lluvia'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspendida')
            ->assertJsonPath('data.suspension_reason', 'Lluvia');

        Notification::assertSentTo($this->tutor, ClassSuspended::class,
            fn (ClassSuspended $n) => $n->body === 'Se suspendió la clase de Fútbol Sub-10 de hoy a las 17:00 (Lluvia).');
        Notification::assertNotSentTo($this->instructor, ClassSuspended::class);

        attendanceApi($this->tutor, 'GET', 'agenda')
            ->assertJsonPath('data.0.class.status', 'suspendida')
            ->assertJsonPath('data.0.can_respond', false);
        attendanceApi($this->instructor, 'PUT', "classes/{$id}/attendance", ['marks' => []])->assertUnprocessable();

        attendanceApi($this->instructor, 'DELETE', "classes/{$id}/suspension")->assertJsonPath('data.status', 'programada');
    });

    it('mis grupos con el % de asistencia del mes', function () {
        $id = todayClassId($this->instructor);
        attendanceApi($this->instructor, 'PUT', "classes/{$id}/attendance", ['marks' => [
            ['student_id' => $this->mateo->id, 'status' => 'presente'],
            ['student_id' => $this->lucas->id, 'status' => 'ausente'],
        ]]);

        attendanceApi($this->instructor, 'GET', 'groups')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Sub-10')
            ->assertJsonPath('data.0.students_count', 2)
            ->assertJsonCount(2, 'data.0.schedules');

        attendanceApi($this->instructor, 'GET', "groups/{$this->sub10->id}?month=2026-09")
            ->assertOk()
            ->assertJsonPath('data.month', '2026-09')
            ->assertJsonPath('data.classes.0.date', '2026-09-28')
            ->assertJsonPath('data.students.0.full_name', 'Lucas Aquino')
            ->assertJsonPath('data.students.0.rate', 0)
            ->assertJsonPath('data.students.1.rate', 100);

        attendanceApi($this->instructor, 'GET', "groups/{$this->sub12->id}")->assertNotFound();
    });
});

describe('tutor', function () {
    it('ve la próxima clase de su hijo y responde', function () {
        attendanceApi($this->tutor, 'GET', 'agenda')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student.first_name', 'Mateo')
            ->assertJsonPath('data.0.class.date', '2026-09-28')
            ->assertJsonPath('data.0.class.starts_at', '17:00')
            ->assertJsonPath('data.0.response', null)
            ->assertJsonPath('data.0.can_respond', true)
            ->assertJsonPath('data.0.class_reminders', null);

        $id = attendanceApi($this->tutor, 'GET', 'agenda')->json('data.0.class.id');

        attendanceApi($this->tutor, 'PUT', "classes/{$id}/students/{$this->mateo->id}/response", ['going' => false])
            ->assertOk()
            ->assertJsonPath('data.response', 'no_va');

        expect(Attendance::query()->withoutGlobalScopes()->sole()->guardian_response)->toBe(GuardianResponse::NotGoing);
    });

    it('después del horario pasa a la clase siguiente; no responde por hijos ajenos', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-28 19:00', 'America/Asuncion'));

        attendanceApi($this->tutor, 'GET', 'agenda')->assertJsonPath('data.0.class.date', '2026-09-30');

        $id = todayClassId($this->instructor);
        attendanceApi($this->tutor, 'PUT', "classes/{$id}/students/{$this->lucas->id}/response", ['going' => true])->assertNotFound();
    });

    it('no puede responder cuando la clase ya empezó', function () {
        $id = attendanceApi($this->tutor, 'GET', 'agenda')->json('data.0.class.id');
        $this->travelTo(CarbonImmutable::parse('2026-09-28 17:05', 'America/Asuncion'));

        attendanceApi($this->tutor, 'GET', 'agenda')->assertJsonPath('data.0.can_respond', false);
        attendanceApi($this->tutor, 'PUT', "classes/{$id}/students/{$this->mateo->id}/response", ['going' => true])
            ->assertUnprocessable();
    });

    it('elige el aviso de los días de clase', function () {
        attendanceApi($this->tutor, 'PUT', "students/{$this->mateo->id}/reminders", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.class_reminders', true);

        attendanceApi($this->tutor, 'GET', "students/{$this->mateo->id}")->assertJsonPath('data.class_reminders', true);
        attendanceApi($this->tutor, 'GET', 'agenda')->assertJsonPath('data.0.class_reminders', true);
        attendanceApi($this->tutor, 'PUT', "students/{$this->lucas->id}/reminders", ['enabled' => true])->assertNotFound();
    });

    it('asistencia del mes de su hijo', function () {
        $id = todayClassId($this->instructor);
        attendanceApi($this->instructor, 'PUT', "classes/{$id}/attendance", ['marks' => [
            ['student_id' => $this->mateo->id, 'status' => 'presente'],
        ]]);

        attendanceApi($this->tutor, 'GET', "students/{$this->mateo->id}/attendance?month=2026-09")
            ->assertOk()
            ->assertJsonPath('data.present', 1)
            ->assertJsonPath('data.rate', 100)
            ->assertJsonPath('data.classes.0.date', '2026-09-28')
            ->assertJsonPath('data.classes.0.attendance', 'presente');
    });
});

describe('aviso de los días de clase', function () {
    beforeEach(function () {
        Notification::fake();
        app(CurrentOrganization::class)->set($this->jakare);
        ClassReminderPreference::query()->create(['user_id' => $this->tutor->id, 'student_id' => $this->mateo->id, 'enabled' => true]);
    });

    it('sale 3 horas antes, una sola vez y solo a quien lo pidió', function () {
        $this->artisan('classes:remind')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-09-28 14:00', 'America/Asuncion'));
        $this->artisan('classes:remind')->assertSuccessful();
        $this->artisan('classes:remind')->assertSuccessful();

        Notification::assertSentToTimes($this->tutor, ClassReminder::class, 1);
        Notification::assertSentTo($this->tutor, ClassReminder::class,
            fn (ClassReminder $n) => $n->body === 'Hoy Mateo tiene Fútbol a las 17:00 (Cancha 1). ¿Lo llevás?');
        Notification::assertNotSentTo($this->instructor, ClassReminder::class);
    });

    it('no avisa si ya respondió', function () {
        $id = todayClassId($this->instructor);
        attendanceApi($this->tutor, 'PUT', "classes/{$id}/students/{$this->mateo->id}/response", ['going' => true]);

        $this->travelTo(CarbonImmutable::parse('2026-09-28 14:00', 'America/Asuncion'));
        $this->artisan('classes:remind');

        Notification::assertNothingSent();
    });

    it('de noche se adelanta a las 20:00 del día anterior', function () {
        $session = new ClassSession(['date' => '2026-09-30', 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']);
        $session->setRelation('organization', $this->jakare);

        expect(SendClassReminders::sendAt($session, 3)->format('Y-m-d H:i'))->toBe('2026-09-29 20:00')
            ->and(SendClassReminders::sendAt($session->fill(['starts_at' => '17:00:00']), 3)->format('Y-m-d H:i'))->toBe('2026-09-30 14:00');
    });
});

it('registra y borra el dispositivo para push', function () {
    attendanceApi($this->tutor, 'POST', 'devices', ['token' => 'abc', 'platform' => 'android'])->assertNoContent();
    attendanceApi($this->instructor, 'POST', 'devices', ['token' => 'abc', 'platform' => 'android'])->assertNoContent();

    expect(DeviceToken::query()->sole()->user_id)->toBe($this->instructor->id);

    attendanceApi($this->tutor, 'DELETE', 'devices/abc')->assertNoContent();
    expect(DeviceToken::query()->count())->toBe(1);
    attendanceApi($this->instructor, 'DELETE', 'devices/abc')->assertNoContent();
    expect(DeviceToken::query()->count())->toBe(0);
});

it('el canal push manda a los dispositivos y borra los inválidos', function () {
    DeviceToken::query()->create(['user_id' => $this->tutor->id, 'token' => 'ok', 'platform' => 'android']);
    DeviceToken::query()->create(['user_id' => $this->tutor->id, 'token' => 'viejo', 'platform' => 'ios']);
    $sender = new class implements PushSender
    {
        public array $sent = [];

        public function send(array $tokens, PushMessage $message): array
        {
            $this->sent[] = [$tokens, $message->body, $message->data];

            return ['viejo'];
        }
    };
    app(CurrentOrganization::class)->set($this->jakare);
    $session = ClassSession::query()->create(['group_id' => $this->sub10->id, 'date' => '2026-09-28', 'starts_at' => '17:00', 'ends_at' => '18:30', 'suspension_reason' => 'Lluvia']);

    (new PushChannel($sender))->send($this->tutor, new ClassSuspended($session));

    expect($sender->sent[0][0])->toBe(['ok', 'viejo'])
        ->and($sender->sent[0][2])->toBe(['type' => 'class_suspended', 'route' => '/inicio'])
        ->and(DeviceToken::query()->pluck('token')->all())->toBe(['ok']);
});

it('cobra por clase asistida al cerrar el período', function () {
    app(CurrentOrganization::class)->set($this->jakare);
    $monthly = FeeConcept::monthlyFee($this->jakare);
    $family = Family::factory()->for($this->jakare)->create();
    $this->mateo->update(['family_id' => $family->id]);

    $colonia = Season::factory()->for($this->jakare)->create([
        'name' => 'Colonia', 'starts_on' => '2026-09-28', 'ends_on' => '2026-10-11',
        'fee_frequency' => 'diaria', 'daily_basis' => 'asistencia', 'daily_grouping' => 'semana', 'due_days' => 5,
    ]);
    Tariff::factory()->create(['fee_concept_id' => $monthly->id, 'season_id' => $colonia->id, 'amount' => 20000, 'valid_from' => '2026-09-28']);
    Enrollment::query()->where('student_id', $this->mateo->id)->update(['season_id' => $colonia->id]);

    // Lunes y miércoles de la primera semana: presente los dos días.
    foreach (['2026-09-28', '2026-09-30'] as $day) {
        $this->travelTo(CarbonImmutable::parse("{$day} 17:30", 'America/Asuncion'));
        $id = todayClassId($this->instructor);
        attendanceApi($this->instructor, 'PUT', "classes/{$id}/attendance", ['marks' => [
            ['student_id' => $this->mateo->id, 'status' => 'presente'],
        ]])->assertOk();
    }

    // Todavía se puede corregir: no hay cuota.
    app(GenerateSeasonCharges::class)->handle($this->jakare, CarbonImmutable::parse('2026-10-05'));
    expect(Charge::query()->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-08 01:00', 'America/Asuncion'));
    app(GenerateSeasonCharges::class)->handle($this->jakare, CarbonImmutable::parse('2026-10-08'));
    app(GenerateSeasonCharges::class)->handle($this->jakare, CarbonImmutable::parse('2026-10-08'));

    $charge = Charge::query()->sole();
    expect([$charge->quantity, $charge->unit_amount, $charge->base_amount])->toBe([2, 20000, 40000])
        ->and($charge->description)->toBe('Cuota semana 28 sep – 4 oct (2 clases)')
        ->and($charge->due_on->toDateString())->toBe('2026-10-13');
});

it('en el panel: toma asistencia del grupo (con los que no van justificados) y suspende', function () {
    Notification::fake();
    $admin = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $admin, Role::query()->where('organization_id', $this->jakare->id)->where('name', OrganizationRole::Admin->value)->firstOrFail());
    $this->actingAs($admin);
    filament()->setTenant($this->jakare);
    app(CurrentOrganization::class)->set($this->jakare);

    $manager = fn () => Livewire::test(ClassSessionsRelationManager::class, ['ownerRecord' => $this->sub10, 'pageClass' => EditGroup::class]);
    $manager()->assertOk();

    $today = ClassSession::query()->whereDate('date', '2026-09-28')->sole();
    Attendance::query()->create(['class_session_id' => $today->id, 'student_id' => $this->mateo->id, 'guardian_response' => GuardianResponse::NotGoing]);

    $manager()
        ->mountTableAction('attendance', $today)
        ->assertTableActionDataSet(['marks' => [$this->lucas->id => 'presente', $this->mateo->id => 'justificado']])
        ->setTableActionData(['marks' => [$this->lucas->id => 'ausente', $this->mateo->id => 'justificado']])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($today->fresh()->counts())->toMatchArray(['absent' => 1, 'justified' => 1]);

    $wednesday = ClassSession::query()->whereDate('date', '2026-09-30')->sole();
    $manager()->callTableAction('suspend', $wednesday, ['reason' => 'Lluvia'])->assertHasNoTableActionErrors();

    expect($wednesday->fresh()->isSuspended())->toBeTrue();
    Notification::assertSentTo($this->tutor, ClassSuspended::class);
});
