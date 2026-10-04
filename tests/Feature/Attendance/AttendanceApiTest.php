<?php

use App\Actions\Attendance\SendClassReminders;
use App\Actions\Billing\GenerateSeasonCharges;
use App\Actions\Billing\RegisterPayment;
use App\Actions\Billing\WaiveSuspendedClass;
use App\Enums\EnrollmentStatus;
use App\Enums\GuardianResponse;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\RelationManagers\ClassSessionsRelationManager;
use App\Models\Attendance;
use App\Models\Charge;
use App\Models\ChargeWaiver;
use App\Models\ClassReminderPreference;
use App\Models\ClassSession;
use App\Models\DeviceToken;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\MoneyAccount;
use App\Models\NotificationSetting;
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
use App\Notifications\ClassRescheduled;
use App\Notifications\ClassSuspended;
use App\Notifications\InstructorClassReminder;
use App\Support\Push\FcmPushSender;
use App\Support\Push\PushChannel;
use App\Support\Push\PushMessage;
use App\Support\Push\PushSender;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\MulticastSendReport;
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

/**
 * La cuota vigente (no anulada) de la primera semana de la colonia.
 */
function currentWeek1(): Charge
{
    return Charge::query()->whereNull('voided_at')->whereDate('period_start', '2026-09-28')->sole();
}

function todayClassId(User $user): int
{
    return attendanceApi($user, 'GET', 'classes')->json('data.0.id');
}

describe('técnico', function () {
    it('ve las clases de hoy de sus grupos y el permiso en la organización', function () {
        attendanceApi($this->instructor, 'GET', 'organization')
            ->assertJsonPath('data.membership.permissions', ['take_attendance', 'collect_payments']);

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

        expect(SendClassReminders::sendAt($session, 180)->format('Y-m-d H:i'))->toBe('2026-09-29 20:00')
            ->and(SendClassReminders::sendAt($session->fill(['starts_at' => '17:00:00']), 180)->format('Y-m-d H:i'))->toBe('2026-09-30 14:00');
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

describe('reprogramar', function () {
    beforeEach(fn () => Notification::fake());

    it('crea la recuperación, deja la original reprogramada y avisa', function () {
        $venue = Venue::factory()->for($this->jakare)->create(['name' => 'Cancha 2']);
        $id = todayClassId($this->instructor);

        attendanceApi($this->instructor, 'POST', "classes/{$id}/reschedule", [
            'date' => '2026-10-03', 'starts_at' => '09:00', 'ends_at' => '10:30', 'venue_id' => $venue->id, 'reason' => 'Lluvia',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'reprogramada')
            ->assertJsonPath('data.rescheduled_to.date', '2026-10-03')
            ->assertJsonPath('data.rescheduled_to.starts_at', '09:00')
            ->assertJsonPath('data.rescheduled_to.venue.name', 'Cancha 2');

        Notification::assertSentTo($this->tutor, ClassRescheduled::class,
            fn (ClassRescheduled $n) => $n->body === 'La clase de Fútbol Sub-10 del lunes 28/09 a las 17:00 por Lluvia pasa al sábado 03/10 a las 09:00 (Cancha 2).');

        attendanceApi($this->instructor, 'GET', 'classes?date=2026-10-03')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_makeup', true)
            ->assertJsonPath('data.0.rescheduled_from.date', '2026-09-28')
            ->assertJsonPath('data.0.counts.enrolled', 2);

        // La original ya no se toma ni se responde; la agenda salta a la siguiente.
        attendanceApi($this->instructor, 'PUT', "classes/{$id}/attendance", ['marks' => [
            ['student_id' => $this->lucas->id, 'status' => 'presente'],
        ]])->assertUnprocessable();
        attendanceApi($this->tutor, 'GET', 'agenda')->assertJsonPath('data.0.class.date', '2026-09-30');
    });

    it('cambia el horario del mismo día sin motivo', function () {
        $id = todayClassId($this->instructor);

        attendanceApi($this->instructor, 'POST', "classes/{$id}/reschedule", ['date' => '2026-09-28', 'starts_at' => '18:30', 'ends_at' => '20:00'])
            ->assertOk();

        Notification::assertSentTo($this->tutor, ClassRescheduled::class,
            fn (ClassRescheduled $n) => $n->body === 'La clase de Fútbol Sub-10 del lunes 28/09 a las 17:00 pasa a las 18:30 (Cancha 1).');
        expect(ClassSession::query()->whereKey($id)->value('suspension_reason'))->toBeNull();
    });

    it('rechaza el pasado, fin antes del inicio y superposiciones', function () {
        $id = todayClassId($this->instructor);
        $reschedule = fn (array $data) => attendanceApi($this->instructor, 'POST', "classes/{$id}/reschedule", $data);

        $reschedule(['date' => '2026-09-28', 'starts_at' => '08:00', 'ends_at' => '09:00'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $reschedule(['date' => '2026-10-03', 'starts_at' => '10:00', 'ends_at' => '09:00'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $reschedule(['date' => '2026-09-30', 'starts_at' => '18:00', 'ends_at' => '19:00'])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $reschedule(['date' => '2026-09-30', 'starts_at' => '18:30', 'ends_at' => '19:30'])->assertOk();
    });

    it('cancelar la reprogramación la vuelve a suspendida y avisa', function () {
        $id = todayClassId($this->instructor);
        attendanceApi($this->instructor, 'POST', "classes/{$id}/reschedule", ['date' => '2026-10-03', 'starts_at' => '09:00', 'ends_at' => '10:30', 'reason' => 'Lluvia']);

        attendanceApi($this->instructor, 'DELETE', "classes/{$id}/reschedule")
            ->assertOk()
            ->assertJsonPath('data.status', 'suspendida')
            ->assertJsonPath('data.rescheduled_to', null);

        expect(ClassSession::query()->where('is_makeup', true)->exists())->toBeFalse();
        Notification::assertSentTo($this->tutor, ClassRescheduled::class, fn (ClassRescheduled $n) => str_contains($n->body, 'ya no se recupera'));
    });

    it('en la recuperación se toma asistencia', function () {
        $id = todayClassId($this->instructor);
        attendanceApi($this->instructor, 'POST', "classes/{$id}/reschedule", ['date' => '2026-10-03', 'starts_at' => '09:00', 'ends_at' => '10:30']);
        $makeup = ClassSession::query()->where('is_makeup', true)->sole();

        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'America/Asuncion'));
        attendanceApi($this->instructor, 'GET', 'classes')->assertJsonPath('data.0.id', $makeup->id);
        attendanceApi($this->instructor, 'PUT', "classes/{$makeup->id}/attendance", ['marks' => [
            ['student_id' => $this->mateo->id, 'status' => 'presente'],
        ]])->assertOk()->assertJsonPath('data.counts.present', 1);
    });

    it('lista las canchas', function () {
        Venue::factory()->for($this->ajena)->create(['name' => 'Ajena']);

        attendanceApi($this->instructor, 'GET', 'venues')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Cancha 1');
    });
});

describe('clase suspendida sin cobrar (por día de entrenamiento)', function () {
    beforeEach(function () {
        Notification::fake();
        app(CurrentOrganization::class)->set($this->jakare);
        $monthly = FeeConcept::monthlyFee($this->jakare);
        $this->family = Family::factory()->for($this->jakare)->create();
        $this->mateo->update(['family_id' => $this->family->id]);

        // Semanal, por día de entrenamiento: Sub-10 entrena lunes y miércoles a ₲ 20.000.
        $this->colonia = Season::factory()->for($this->jakare)->create([
            'name' => 'Colonia', 'starts_on' => '2026-09-28', 'ends_on' => '2026-10-11',
            'fee_frequency' => 'diaria', 'daily_basis' => 'entrenamiento', 'daily_grouping' => 'semana', 'due_days' => 3,
        ]);
        Tariff::factory()->create(['fee_concept_id' => $monthly->id, 'season_id' => $this->colonia->id, 'amount' => 20000, 'valid_from' => '2026-09-28']);
        Enrollment::query()->where('student_id', $this->mateo->id)->update(['season_id' => $this->colonia->id]);

        app(GenerateSeasonCharges::class)->handle($this->jakare, CarbonImmutable::parse('2026-09-28'));
        $this->week1 = Charge::query()->sole();
        $this->wednesday = fn () => attendanceApi($this->instructor, 'GET', 'classes?date=2026-09-30')->json('data.0.id');
    });

    it('la cuota impaga se anula y se reemite sin ese día; volver a programar la reemite con él', function () {
        expect($this->week1->final_amount)->toBe(40000);
        $id = ($this->wednesday)();
        attendanceApi($this->instructor, 'GET', "classes/{$id}")->assertJsonPath('data.can_waive_charge', true);

        attendanceApi($this->instructor, 'POST', "classes/{$id}/suspension", ['reason' => 'Lluvia', 'waive_charge' => true])
            ->assertOk()
            ->assertJsonPath('data.charge_waived', true);

        // La cuota emitida no se modifica: se anula con motivo y sale otra con un día menos.
        expect($this->week1->fresh())
            ->isVoided()->toBeTrue()
            ->final_amount->toBe(40000)
            ->void_reason->toBe('Se vuelve a emitir sin la clase suspendida 30/09.');
        expect(currentWeek1())->quantity->toBe(1)->final_amount->toBe(20000)
            ->and(currentWeek1()->adjustments()->count())->toBe(0);

        // Idempotente: suspender de nuevo no reemite otra vez.
        app(WaiveSuspendedClass::class)->apply(ClassSession::query()->find($id));
        expect(Charge::query()->count())->toBe(2);

        attendanceApi($this->instructor, 'DELETE', "classes/{$id}/suspension")->assertOk();
        expect(currentWeek1())->quantity->toBe(2)->final_amount->toBe(40000)
            ->and(Charge::query()->whereNotNull('voided_at')->count())->toBe(2);
    });

    it('sin la casilla no descuenta', function () {
        attendanceApi($this->instructor, 'POST', 'classes/'.($this->wednesday)().'/suspension', ['reason' => 'Lluvia'])
            ->assertJsonPath('data.charge_waived', false);

        expect($this->week1->fresh()->final_amount)->toBe(40000);
    });

    it('si la cuota todavía no se emitió, sale con un día menos', function () {
        $monday = attendanceApi($this->instructor, 'GET', 'classes?date=2026-10-05')->json('data.0.id');
        attendanceApi($this->instructor, 'POST', "classes/{$monday}/suspension", ['reason' => 'Feriado', 'waive_charge' => true])->assertOk();

        app(GenerateSeasonCharges::class)->handle($this->jakare, CarbonImmutable::parse('2026-10-05'));

        $week2 = Charge::query()->whereDate('period_start', '2026-10-05')->sole();
        expect([$week2->quantity, $week2->final_amount])->toBe([1, 20000])
            ->and($week2->adjustments()->count())->toBe(0);
    });

    it('si la cuota ya se pagó, el descuento va a la próxima', function () {
        app(RegisterPayment::class)->handle($this->family, MoneyAccount::query()->first(), 40000, PaymentMethod::Cash, CarbonImmutable::parse('2026-09-28'), allocations: [$this->week1->id => 40000]);

        attendanceApi($this->instructor, 'POST', 'classes/'.($this->wednesday)().'/suspension', ['reason' => 'Lluvia', 'waive_charge' => true])->assertOk();
        expect($this->week1->fresh()->final_amount)->toBe(40000)
            ->and(ChargeWaiver::query()->sole()->applied_charge_id)->toBeNull();

        app(GenerateSeasonCharges::class)->handle($this->jakare, CarbonImmutable::parse('2026-10-05'));

        $week2 = Charge::query()->whereDate('period_start', '2026-10-05')->sole();
        expect($week2->final_amount)->toBe(20000)
            ->and($week2->adjustments()->sole()->label)->toBe('Clase suspendida 30/09')
            ->and(ChargeWaiver::query()->sole()->applied_charge_id)->toBe($week2->id);
    });

    it('reprogramar no descuenta y deshace el descuento', function () {
        $id = ($this->wednesday)();
        attendanceApi($this->instructor, 'POST', "classes/{$id}/suspension", ['reason' => 'Lluvia', 'waive_charge' => true]);
        expect(currentWeek1()->final_amount)->toBe(20000);

        attendanceApi($this->instructor, 'POST', "classes/{$id}/reschedule", ['date' => '2026-10-03', 'starts_at' => '09:00', 'ends_at' => '10:30'])
            ->assertOk()
            ->assertJsonPath('data.charge_waived', false);

        expect(currentWeek1()->final_amount)->toBe(40000);
    });

    it('si la cuota ya se pagó y la próxima ya está emitida impaga, la próxima se reemite con el descuento', function () {
        app(GenerateSeasonCharges::class)->handle($this->jakare, CarbonImmutable::parse('2026-10-05'));
        app(RegisterPayment::class)->handle($this->family, MoneyAccount::query()->first(), 40000, PaymentMethod::Cash, CarbonImmutable::parse('2026-09-28'), allocations: [$this->week1->id => 40000]);
        $week2 = Charge::query()->whereDate('period_start', '2026-10-05')->sole();

        attendanceApi($this->instructor, 'POST', 'classes/'.($this->wednesday)().'/suspension', ['reason' => 'Lluvia', 'waive_charge' => true])->assertOk();

        expect($this->week1->fresh()->isVoided())->toBeFalse()
            ->and($week2->fresh()->isVoided())->toBeTrue();
        $reissued = Charge::query()->whereNull('voided_at')->whereDate('period_start', '2026-10-05')->sole();
        expect($reissued->final_amount)->toBe(20000)
            ->and($reissued->adjustments()->sole()->label)->toBe('Clase suspendida 30/09')
            ->and(ChargeWaiver::query()->sole()->applied_charge_id)->toBe($reissued->id);

        // Volver a programar: el descuento se borra y la próxima vuelve a su monto.
        attendanceApi($this->instructor, 'DELETE', 'classes/'.($this->wednesday)().'/suspension')->assertOk();
        expect(Charge::query()->whereNull('voided_at')->whereDate('period_start', '2026-10-05')->sole()->final_amount)->toBe(40000)
            ->and(ChargeWaiver::query()->count())->toBe(0);
    });

    it('por clase dictada: la cuota sale al cerrar el período, sin las suspendidas y con las recuperaciones', function () {
        // Se empieza sin la cuota por día de entrenamiento de la preparación.
        Charge::query()->update(['voided_at' => now(), 'void_reason' => 'Prueba', 'unique_key' => null]);
        $this->colonia->update(['daily_basis' => 'dictado']);
        $wednesday = ($this->wednesday)();

        // Por clase dictada no hay casilla: lo suspendido nunca se cobra.
        attendanceApi($this->instructor, 'GET', "classes/{$wednesday}")->assertJsonPath('data.can_waive_charge', false);
        attendanceApi($this->instructor, 'POST', "classes/{$wednesday}/suspension", ['reason' => 'Lluvia'])->assertOk();

        // Durante el período no se emite nada.
        app(GenerateSeasonCharges::class)->handle($this->jakare, CarbonImmutable::parse('2026-10-01'));
        expect(Charge::query()->whereNull('voided_at')->count())->toBe(0);

        // Cerrado el período (y los días para corregir): lunes 28 sí, miércoles 30 suspendido.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'America/Asuncion'));
        app(GenerateSeasonCharges::class)->handle($this->jakare, CarbonImmutable::parse('2026-10-09'));
        $charge = Charge::query()->whereNull('voided_at')->sole();
        expect([$charge->quantity, $charge->final_amount, $charge->description])->toBe([1, 20000, 'Cuota semana 28 sep – 4 oct (1 clase)']);
    });

    it('por clase dictada, la recuperación cuenta', function () {
        // Se empieza sin la cuota por día de entrenamiento de la preparación.
        Charge::query()->update(['voided_at' => now(), 'void_reason' => 'Prueba', 'unique_key' => null]);
        $this->colonia->update(['daily_basis' => 'dictado']);
        attendanceApi($this->instructor, 'POST', 'classes/'.($this->wednesday)().'/reschedule', ['date' => '2026-10-03', 'starts_at' => '09:00', 'ends_at' => '10:30'])->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'America/Asuncion'));
        app(GenerateSeasonCharges::class)->handle($this->jakare, CarbonImmutable::parse('2026-10-09'));
        expect(Charge::query()->whereNull('voided_at')->sole()->quantity)->toBe(2);
    });

    it('con cuota fija no se puede no cobrar', function () {
        $this->colonia->update(['fee_frequency' => 'mensual', 'daily_basis' => null]);
        $id = ($this->wednesday)();

        attendanceApi($this->instructor, 'GET', "classes/{$id}")->assertJsonPath('data.can_waive_charge', false);
        attendanceApi($this->instructor, 'POST', "classes/{$id}/suspension", ['reason' => 'Lluvia', 'waive_charge' => true])
            ->assertJsonPath('data.charge_waived', false);
        expect($this->week1->fresh()->final_amount)->toBe(40000);
    });
});

describe('avisos configurables', function () {
    beforeEach(function () {
        Notification::fake();
        app(CurrentOrganization::class)->set($this->jakare);
    });

    it('muestra los avisos del club por defecto y guarda los elegidos', function () {
        attendanceApi($this->tutor, 'PUT', "students/{$this->mateo->id}/reminders", ['enabled' => true]);

        attendanceApi($this->tutor, 'GET', 'me/notification-settings')
            ->assertOk()
            ->assertJsonPath('data.instructor', null)
            ->assertJsonPath('data.guardian.offsets', [180])
            ->assertJsonPath('data.guardian.students.0.first_name', 'Mateo')
            ->assertJsonPath('data.guardian.students.0.enabled', true)
            ->assertJsonPath('data.options.0.value', 'eve')
            ->assertJsonPath('data.max', 3);

        attendanceApi($this->instructor, 'GET', 'me/notification-settings')
            ->assertJsonPath('data.instructor', ['enabled' => true, 'offsets' => [120]])
            ->assertJsonPath('data.guardian', null);

        attendanceApi($this->tutor, 'PUT', 'me/notification-settings', ['guardian' => ['offsets' => [60, 'eve', 60]]])
            ->assertOk()
            ->assertJsonPath('data.guardian.offsets', ['eve', 60]);
    });

    it('valida la cantidad y las opciones', function () {
        attendanceApi($this->tutor, 'PUT', 'me/notification-settings', ['guardian' => ['offsets' => ['eve', 360, 180, 60]]])
            ->assertUnprocessable()->assertJsonValidationErrors('guardian.offsets');
        attendanceApi($this->tutor, 'PUT', 'me/notification-settings', ['guardian' => ['offsets' => [45]]])
            ->assertUnprocessable()->assertJsonValidationErrors('guardian.offsets.0');
        attendanceApi($this->tutor, 'PUT', 'me/notification-settings', ['guardian' => ['offsets' => []]])
            ->assertUnprocessable();
    });

    it('el tutor recibe cada aviso elegido mientras no responda', function () {
        ClassReminderPreference::query()->create(['user_id' => $this->tutor->id, 'student_id' => $this->mateo->id, 'enabled' => true]);
        NotificationSetting::query()->create(['user_id' => $this->tutor->id, 'guardian_offsets' => ['eve', 60]]);
        // Solo se cuentan los del tutor.
        NotificationSetting::query()->create(['user_id' => $this->instructor->id, 'instructor_enabled' => false]);
        $remind = fn (string $at) => app(SendClassReminders::class)->handle($this->jakare, CarbonImmutable::parse($at, 'America/Asuncion'));

        // El miércoles 30/09 a las 17:00: aviso el martes 20:00 y el miércoles 16:00.
        expect($remind('2026-09-29 19:59'))->toBe(0)
            ->and($remind('2026-09-29 20:00'))->toBe(1)
            ->and($remind('2026-09-29 20:15'))->toBe(0)
            ->and($remind('2026-09-30 16:00'))->toBe(1)
            ->and($remind('2026-09-30 16:15'))->toBe(0);

        Notification::assertSentTo($this->tutor, ClassReminder::class,
            fn (ClassReminder $n) => $n->body === 'Mañana Mateo tiene Fútbol a las 17:00 (Cancha 1). ¿Lo llevás?');
    });

    it('si ya respondió, no llegan los avisos siguientes', function () {
        ClassReminderPreference::query()->create(['user_id' => $this->tutor->id, 'student_id' => $this->mateo->id, 'enabled' => true]);
        NotificationSetting::query()->create(['user_id' => $this->tutor->id, 'guardian_offsets' => [360, 60]]);
        // Solo se cuentan los del tutor.
        NotificationSetting::query()->create(['user_id' => $this->instructor->id, 'instructor_enabled' => false]);
        $remind = fn (string $at) => app(SendClassReminders::class)->handle($this->jakare, CarbonImmutable::parse($at, 'America/Asuncion'));

        expect($remind('2026-09-28 11:00'))->toBe(1);
        $id = todayClassId($this->instructor);
        attendanceApi($this->tutor, 'PUT', "classes/{$id}/students/{$this->mateo->id}/response", ['going' => true]);

        expect($remind('2026-09-28 16:00'))->toBe(0);
    });

    it('un solo aviso por usuario con varios hijos en la clase', function () {
        $this->lucas->guardians()->attach(Guardian::query()->where('user_id', $this->tutor->id)->sole(), ['relationship' => 'madre']);
        foreach ([$this->mateo, $this->lucas] as $child) {
            ClassReminderPreference::query()->create(['user_id' => $this->tutor->id, 'student_id' => $child->id, 'enabled' => true]);
        }

        expect(app(SendClassReminders::class)->handle($this->jakare, CarbonImmutable::parse('2026-09-28 14:00', 'America/Asuncion')))->toBe(1);

        Notification::assertSentToTimes($this->tutor, ClassReminder::class, 1);
        Notification::assertSentTo($this->tutor, ClassReminder::class,
            fn (ClassReminder $n) => $n->body === 'Hoy Lucas y Mateo tienen Fútbol a las 17:00 (Cancha 1). ¿Los llevás?');
    });

    it('el técnico recibe su aviso con los contadores y lo puede apagar', function () {
        $id = todayClassId($this->instructor);
        attendanceApi($this->tutor, 'PUT', "classes/{$id}/students/{$this->mateo->id}/response", ['going' => false]);
        $remind = fn (string $at) => app(SendClassReminders::class)->handle($this->jakare, CarbonImmutable::parse($at, 'America/Asuncion'));

        expect($remind('2026-09-28 14:59'))->toBe(0)
            ->and($remind('2026-09-28 15:00'))->toBe(1)
            ->and($remind('2026-09-28 15:30'))->toBe(0);
        Notification::assertSentTo($this->instructor, InstructorClassReminder::class,
            fn (InstructorClassReminder $n) => $n->body === 'Hoy tenés clase con Sub-10 a las 17:00 (Cancha 1) · 0 van, 1 no van, 1 sin responder'
                && $n->toPush($this->instructor)->data['route'] === "/clases/{$id}");

        attendanceApi($this->instructor, 'PUT', 'me/notification-settings', ['instructor' => ['enabled' => false]])->assertOk();
        expect($remind('2026-09-30 15:00'))->toBe(0);
    });
});

describe('responder desde la notificación', function () {
    beforeEach(function () {
        Notification::fake();
        app(CurrentOrganization::class)->set($this->jakare);
        ClassReminderPreference::query()->create(['user_id' => $this->tutor->id, 'student_id' => $this->mateo->id, 'enabled' => true]);
        app(SendClassReminders::class)->handle($this->jakare, CarbonImmutable::parse('2026-09-28 14:00', 'America/Asuncion'));

        $this->push = null;
        Notification::assertSentTo($this->tutor, ClassReminder::class, function (ClassReminder $n) {
            $this->push = $n->toPush($this->tutor);

            return true;
        });
    });

    it('trae los botones con links firmados', function () {
        expect($this->push->withActions)->toBeTrue()
            ->and($this->push->category)->toBe('CLASS_REMINDER')
            ->and($this->push->data['type'])->toBe('class_reminder')
            ->and($this->push->data['student_ids'])->toBe((string) $this->mateo->id);
    });

    it('"No va" responde sin sesión', function () {
        $this->postJson($this->push->data['not_going_url'])
            ->assertOk()
            ->assertJsonPath('data.message', 'Listo: avisaste que Mateo no va.');

        expect(Attendance::query()->withoutGlobalScopes()->sole()->guardian_response)->toBe(GuardianResponse::NotGoing);
    });

    it('alterado o vencido → 403', function () {
        $this->postJson(str_replace('going=0', 'going=1', $this->push->data['not_going_url']))->assertForbidden();

        $this->travelTo(CarbonImmutable::parse('2026-09-28 17:01', 'America/Asuncion'));
        $this->postJson($this->push->data['going_url'])->assertForbidden();
    });

    it('clase suspendida → 422', function () {
        ClassSession::query()->withoutGlobalScopes()->update(['status' => 'suspendida']);

        $this->postJson($this->push->data['going_url'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La clase está suspendida.');
    });
});

it('el push con botones va solo con datos en Android y con categoría en iOS', function () {
    $sent = null;
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->andReturnUsing(function ($message) use (&$sent) {
        $sent = $message->jsonSerialize();

        return MulticastSendReport::withItems([]);
    });

    (new FcmPushSender($messaging))->send(['t'], new PushMessage('Día de clase', 'Hoy Mateo…', ['type' => 'class_reminder'], withActions: true, category: 'CLASS_REMINDER'));

    expect($sent)->not->toHaveKey('notification')
        ->and($sent['data'])->toMatchArray(['type' => 'class_reminder', 'title' => 'Día de clase', 'body' => 'Hoy Mateo…'])
        ->and($sent['android']['priority'])->toBe('high')
        ->and($sent['apns']['payload']['aps']['category'])->toBe('CLASS_REMINDER');
});

it('en el panel: suspender y reprogramar, y cancelar la reprogramación', function () {
    Notification::fake();
    $admin = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $admin, Role::query()->where('organization_id', $this->jakare->id)->where('name', OrganizationRole::Admin->value)->firstOrFail());
    $this->actingAs($admin);
    filament()->setTenant($this->jakare);
    app(CurrentOrganization::class)->set($this->jakare);

    $manager = fn () => Livewire::test(ClassSessionsRelationManager::class, ['ownerRecord' => $this->sub10, 'pageClass' => EditGroup::class]);
    $manager()->assertOk();
    $wednesday = ClassSession::query()->whereDate('date', '2026-09-30')->sole();

    $manager()
        ->callTableAction('suspend', $wednesday, [
            'reason' => 'Lluvia', 'then' => 'reschedule', 'date' => '2026-10-03', 'starts_at' => '09:00', 'ends_at' => '10:30',
        ])
        ->assertHasNoTableActionErrors();

    $wednesday->refresh();
    expect($wednesday->isRescheduled())->toBeTrue()
        ->and($wednesday->rescheduledTo->date->toDateString())->toBe('2026-10-03')
        ->and($wednesday->suspension_reason)->toBe('Lluvia');
    Notification::assertSentTo($this->tutor, ClassRescheduled::class);

    $manager()->callTableAction('cancel_reschedule', $wednesday)->assertHasNoTableActionErrors();
    expect($wednesday->fresh()->isSuspended())->toBeTrue();
});

describe('copia por correo de los avisos', function () {
    it('el aviso sale también por correo con la marca a quien tiene un correo para copias', function () {
        $session = ClassSession::query()->withoutGlobalScopes()->find(todayClassId($this->instructor));
        $suspended = new ClassSuspended($session);

        $phoneOnly = User::factory()->create(['email' => null, 'phone' => '+595981000111', 'phone_verified_at' => now()]);
        $unconfirmed = User::factory()->unverified()->create(['phone' => '+595981000222', 'phone_verified_at' => now()]);
        expect($suspended->via($this->tutor))->toBe(['push', 'mail'])
            ->and($suspended->via($phoneOnly))->toBe(['push'])
            ->and($suspended->via($unconfirmed))->toBe(['push']);

        $mail = $suspended->toMail($this->tutor);
        expect($mail->subject)->toBe('Clase suspendida')
            ->and((string) $mail->render())->toContain('Hola, Ana:')
            ->toContain('Se suspendió la clase de Fútbol Sub-10 de hoy a las 17:00.')
            ->toContain('brand/correo/tuku-descansa.png')
            ->toContain('Ver en Tuku')
            ->not->toContain('Regards');

        $instructor = (new InstructorClassReminder($session, $this->jakare->today()))->toMail($this->instructor);
        expect($instructor->viewData['actions'])->toBe([
            ['label' => 'Tomar asistencia', 'url' => rtrim(config('app.frontend_url'), '/')."/clases/{$session->id}"],
        ]);
    });

    it('"Sí, va" y "No va" del correo abren una página y la respuesta se guarda con su botón', function () {
        $session = ClassSession::query()->withoutGlobalScopes()->find(todayClassId($this->instructor));
        $mail = (new ClassReminder($session, $this->mateo, $this->jakare->today(), $this->tutor->id))->toMail($this->tutor);
        [$going, $notGoing] = $mail->viewData['actions'];
        expect($going['label'])->toBe('Sí, va')->and($notGoing['label'])->toBe('No va');

        // Abrir el link (o que lo abra el antivirus del correo) no cambia nada.
        $this->get($notGoing['url'])->assertOk()
            ->assertSee('Día de clase')
            ->assertSee('Hoy Mateo tiene Fútbol a las 17:00 (Cancha 1). ¿Lo llevás?')
            ->assertSee('Sí, va');
        expect(Attendance::query()->withoutGlobalScopes()->count())->toBe(0);

        $this->post($notGoing['url'])->assertOk()->assertSee('Listo: avisaste que Mateo no va.');
        expect(Attendance::query()->withoutGlobalScopes()->sole()->guardian_response)->toBe(GuardianResponse::NotGoing);

        $this->post($going['url'])->assertOk()->assertSee('Listo: avisaste que Mateo va.');
        expect(Attendance::query()->withoutGlobalScopes()->sole()->guardian_response)->toBe(GuardianResponse::Going);
    });

    it('el link vence al empezar la clase y no se puede cambiar', function () {
        $session = ClassSession::query()->withoutGlobalScopes()->find(todayClassId($this->instructor));
        [$going] = (new ClassReminder($session, $this->mateo, $this->jakare->today(), $this->tutor->id))->toMail($this->tutor)->viewData['actions'];

        $this->get(str_replace("students={$this->mateo->id}", "students={$this->lucas->id}", $going['url']))->assertForbidden();

        $this->travelTo(CarbonImmutable::parse('2026-09-28 17:05', 'America/Asuncion'));
        $this->get($going['url'])->assertForbidden()->assertSee('Este link venció');
        $this->post($going['url'])->assertForbidden();
        expect(Attendance::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('si la clase se suspendió, la página lo explica', function () {
        $id = todayClassId($this->instructor);
        $session = ClassSession::query()->withoutGlobalScopes()->find($id);
        [$going] = (new ClassReminder($session, $this->mateo, $this->jakare->today(), $this->tutor->id))->toMail($this->tutor)->viewData['actions'];
        Notification::fake();
        attendanceApi($this->instructor, 'POST', "classes/{$id}/suspension", ['reason' => 'Lluvia'])->assertOk();

        $this->post($going['url'])->assertUnprocessable()->assertSee('La clase está suspendida.');
    });
});
