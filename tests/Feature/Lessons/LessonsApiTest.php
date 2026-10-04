<?php

use App\Actions\Attendance\SendClassReminders;
use App\Actions\Lessons\ExpireClassPacks;
use App\Enums\BookingStatus;
use App\Enums\ChargeStatus;
use App\Enums\ClassPackStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Lessons\Pages\ListBookings;
use App\Filament\Resources\Lessons\Pages\ListClassPacks;
use App\Filament\Resources\Lessons\Pages\ManageLessonProfiles;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Charge;
use App\Models\ClassPack;
use App\Models\Family;
use App\Models\Guardian;
use App\Models\LessonPack;
use App\Models\LessonProfile;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Notifications\LessonBooked;
use App\Notifications\LessonCancelled;
use App\Notifications\LessonReminder;
use App\Notifications\PackExpiring;
use App\Notifications\PackLow;
use App\Notifications\TeacherDayReminder;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    // Lunes 28/09/2026 a las 10:00 en Asunción.
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Asuncion'));
    Notification::fake();

    $this->org = Organization::factory()->create(['slug' => 'profe', 'features' => ['private_lessons']]);
    $this->ajena = Organization::factory()->create(['slug' => 'ajena', 'features' => ['private_lessons']]);

    $this->teacher = memberOf($this->org, ['name' => 'Carlos Gómez']);
    app(RoleAssigner::class)->assign(
        $this->org,
        $this->teacher,
        Role::query()->where('organization_id', $this->org->id)->where('name', OrganizationRole::Instructor->value)->firstOrFail(),
    );

    $this->profile = LessonProfile::query()->create([
        'organization_id' => $this->org->id,
        'user_id' => $this->teacher->id,
        'enabled' => true,
        'duration_minutes' => 60,
        'single_price' => 35000,
        'min_notice_minutes' => 120,
        'days_ahead' => 30,
    ]);
    // Lunes a viernes de 15:00 a 18:00 (tres clases por día).
    foreach (range(1, 5) as $weekday) {
        AvailabilitySlot::query()->create(['organization_id' => $this->org->id, 'user_id' => $this->teacher->id, 'weekday' => $weekday, 'starts_at' => '15:00', 'ends_at' => '18:00']);
    }
    $this->offer = LessonPack::query()->create(['organization_id' => $this->org->id, 'user_id' => $this->teacher->id, 'classes' => 4, 'price' => 100000, 'valid_days' => 60]);

    // Alumna adulta (es su propia responsable) y un tutor con su hijo.
    $this->adult = memberOf($this->org, ['name' => 'Laura Ríos']);
    $this->laura = Student::factory()->for($this->org)->create(['first_name' => 'Laura', 'last_name' => 'Ríos', 'user_id' => $this->adult->id, 'birth_date' => '1990-05-01']);

    $this->tutor = memberOf($this->org, ['name' => 'Ana Benítez']);
    $guardian = Guardian::factory()->for($this->org)->create(['first_name' => 'Ana', 'last_name' => 'Benítez', 'user_id' => $this->tutor->id]);
    $this->mateo = Student::factory()->for($this->org)->create(['first_name' => 'Mateo', 'last_name' => 'Benítez', 'birth_date' => '2015-03-01']);
    $this->mateo->guardians()->attach($guardian, ['relationship' => 'madre']);
    Family::syncFor($this->mateo);
});

function lessonsApi(User $user, string $method, string $uri, array $data = [], string $organization = 'profe')
{
    return test()->actingAs($user, 'sanctum')->json($method, "/api/v1/{$uri}", $data, ['X-Organization' => $organization]);
}

function book(User $user, Student $student, string $date = '2026-09-29', string $time = '16:00')
{
    return lessonsApi($user, 'POST', 'bookings', ['student_id' => $student->id, 'teacher_id' => test()->teacher->id, 'date' => $date, 'starts_at' => $time]);
}

/**
 * Paquete comprado por Laura y cobrado por el profesor.
 */
function paidPack(): ClassPack
{
    $packId = lessonsApi(test()->adult, 'POST', 'lessons/packs/'.test()->offer->id.'/buy', ['student_id' => test()->laura->id])->json('data.id');
    lessonsApi(test()->teacher, 'POST', 'teacher/payments', ['student_id' => test()->laura->id, 'amount' => 100000, 'method' => 'efectivo', 'class_pack_id' => $packId])->assertCreated();

    return ClassPack::query()->findOrFail($packId);
}

describe('módulo y permisos', function () {
    it('el profesor tiene teach_lessons; sin el módulo no hay clases particulares', function () {
        lessonsApi($this->teacher, 'GET', 'organization')->assertJsonPath('data.membership.permissions', ['teach_lessons']);
        lessonsApi($this->adult, 'GET', 'organization')->assertJsonPath('data.membership.permissions', []);

        $this->org->update(['features' => []]);
        lessonsApi($this->adult, 'GET', 'lessons/teachers')->assertNotFound();
        lessonsApi($this->teacher, 'GET', 'organization')->assertJsonPath('data.membership.permissions', []);
    });

    it('lista los profesores con precios, paquetes y los alumnos a cargo', function () {
        lessonsApi($this->tutor, 'GET', 'lessons/teachers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Carlos Gómez')
            ->assertJsonPath('data.0.single_price', 35000)
            ->assertJsonPath('data.0.packs', [['id' => $this->offer->id, 'classes' => 4, 'price' => 100000, 'valid_days' => 60]])
            ->assertJsonPath('data.0.students.0.student.first_name', 'Mateo')
            ->assertJsonPath('data.0.students.0.pack', null);

        $this->profile->update(['enabled' => false]);
        lessonsApi($this->tutor, 'GET', 'lessons/teachers')->assertJsonCount(0, 'data');
    });
});

describe('horas libres y reservas', function () {
    it('parte la disponibilidad por la duración y respeta la anticipación', function () {
        $days = lessonsApi($this->adult, 'GET', 'lessons/teachers/'.$this->teacher->id.'/slots?from=2026-09-28&to=2026-10-04')
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 60)
            ->json('data.days');

        // Lunes a viernes (sin sábado ni domingo).
        expect(collect($days)->pluck('date')->all())->toBe(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'])
            ->and($days[0]['times'])->toBe(['15:00', '16:00', '17:00']);

        // A las 14:30, con 2 h de anticipación, hoy solo queda la de las 17:00.
        $this->travelTo(CarbonImmutable::parse('2026-09-28 14:30', 'America/Asuncion'));
        lessonsApi($this->adult, 'GET', 'lessons/teachers/'.$this->teacher->id.'/slots?from=2026-09-28&to=2026-09-28')
            ->assertJsonPath('data.days.0.times', ['17:00']);
    });

    it('reserva suelta, saca la hora de las libres y avisa al profesor', function () {
        book($this->adult, $this->laura)
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmada')
            ->assertJsonPath('data.payment', 'suelta')
            ->assertJsonPath('data.price', 35000)
            ->assertJsonPath('data.starts_at', '16:00')
            ->assertJsonPath('data.ends_at', '17:00')
            ->assertJsonPath('data.can_cancel', true);

        lessonsApi($this->adult, 'GET', 'lessons/teachers/'.$this->teacher->id.'/slots?from=2026-09-29&to=2026-09-29')
            ->assertJsonPath('data.days.0.times', ['15:00', '17:00']);

        Notification::assertSentTo($this->teacher, LessonBooked::class, fn (LessonBooked $n) => $n->body === 'Laura Ríos, mañana a las 16:00 (clase suelta).');
        // El alumno adulto sin tutores queda con familia (los pagos son por familia).
        expect($this->laura->fresh()->family_id)->not->toBeNull();
    });

    it('no reserva una hora tomada, fuera de la disponibilidad ni para alumnos ajenos', function () {
        book($this->adult, $this->laura)->assertCreated();

        book($this->tutor, $this->mateo)->assertUnprocessable()->assertJsonPath('errors.starts_at.0', 'Ese horario ya se reservó. Elegí otro.');
        book($this->tutor, $this->mateo, time: '19:00')->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        book($this->tutor, $this->mateo, date: '2026-10-03')->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        book($this->tutor, $this->laura)->assertUnprocessable()->assertJsonValidationErrors('student_id');
    });

    it('cancelar libera la hora y avisa al profesor; el profesor cancela y avisa al alumno', function () {
        $id = book($this->adult, $this->laura)->json('data.id');

        lessonsApi($this->tutor, 'DELETE', "bookings/{$id}")->assertNotFound();
        lessonsApi($this->adult, 'DELETE', "bookings/{$id}")->assertOk()->assertJsonPath('data.status', 'cancelada_alumno');
        Notification::assertSentTo($this->teacher, LessonCancelled::class);

        // La hora vuelve a estar libre y se puede reservar de nuevo.
        $other = book($this->tutor, $this->mateo)->assertCreated()->json('data.id');
        lessonsApi($this->teacher, 'DELETE', "teacher/bookings/{$other}", ['reason' => 'Estoy enfermo'])
            ->assertOk()->assertJsonPath('data.status', 'cancelada_profesor');
        Notification::assertSentTo($this->tutor, LessonCancelled::class, fn (LessonCancelled $n) => str_contains($n->body, '(Estoy enfermo)'));

        lessonsApi($this->adult, 'GET', 'bookings')->assertJsonCount(0, 'data.upcoming');
    });

    it('no se ve desde otra organización', function () {
        book($this->adult, $this->laura)->assertCreated();
        $ajeno = memberOf($this->ajena);

        lessonsApi($ajeno, 'GET', 'lessons/teachers', organization: 'ajena')->assertJsonCount(0, 'data');
        lessonsApi($ajeno, 'GET', 'bookings', organization: 'ajena')->assertJsonCount(0, 'data.upcoming');
    });
});

describe('paquetes', function () {
    it('se compra pendiente de pago y se activa al cobrarlo; las reservas lo usan', function () {
        $buy = lessonsApi($this->adult, 'POST', 'lessons/packs/'.$this->offer->id.'/buy', ['student_id' => $this->laura->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pendiente_pago')
            ->assertJsonPath('data.charge.amount', 100000)
            ->assertJsonPath('data.charge.pending', 100000)
            ->assertJsonPath('data.activated_on', null);

        $charge = Charge::query()->findOrFail($buy->json('data.charge.id'));
        // Vence hoy: todavía está pendiente, no vencido (fecha local de la organización).
        expect($charge->description)->toBe('Paquete 4 clases con Carlos Gómez')
            ->and($charge->status())->toBe(ChargeStatus::Pending);

        // Uno pendiente por profesor.
        lessonsApi($this->adult, 'POST', 'lessons/packs/'.$this->offer->id.'/buy', ['student_id' => $this->laura->id])
            ->assertUnprocessable();

        lessonsApi($this->teacher, 'POST', 'teacher/payments', ['student_id' => $this->laura->id, 'amount' => 100000, 'method' => 'transferencia', 'class_pack_id' => $buy->json('data.id')])
            ->assertCreated()
            ->assertJsonPath('data.applied', 100000)
            ->assertJsonPath('data.credit', 0)
            ->assertJsonPath('data.message', 'Cobrado ₲ 100.000.')
            ->assertJsonPath('data.pack.status', 'activo')
            ->assertJsonPath('data.pack.activated_on', '2026-09-28')
            ->assertJsonPath('data.pack.expires_on', '2026-11-26');

        book($this->adult, $this->laura)->assertCreated()->assertJsonPath('data.payment', 'paquete');

        lessonsApi($this->adult, 'GET', 'lessons/teachers')
            ->assertJsonPath('data.0.students.0.pack.used', 0)
            ->assertJsonPath('data.0.students.0.pack.reserved', 1)
            ->assertJsonPath('data.0.students.0.pack.available', 3)
            ->assertJsonPath('data.0.students.0.next_booking.date', '2026-09-29');
    });

    it('con saldo a favor suficiente queda activo enseguida', function () {
        $book = book($this->adult, $this->laura)->json('data.id');
        lessonsApi($this->teacher, 'POST', 'teacher/payments', ['student_id' => $this->laura->id, 'amount' => 100000, 'method' => 'efectivo', 'booking_id' => $book])
            ->assertJsonPath('data.credit', 100000)
            ->assertJsonPath('data.message', 'Cobrado ₲ 100.000. Quedan ₲ 100.000 a favor.');

        lessonsApi($this->adult, 'POST', 'lessons/packs/'.$this->offer->id.'/buy', ['student_id' => $this->laura->id])
            ->assertJsonPath('data.status', 'activo');
    });

    it('"Vino" usa una clase, la corrección la devuelve y "No vino" no descuenta', function () {
        $pack = paidPack();
        $this->travelTo(CarbonImmutable::parse('2026-09-28 11:00', 'America/Asuncion'));
        $id = book($this->adult, $this->laura, date: '2026-09-28', time: '15:00')->json('data.id');

        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$id}/attendance", ['attended' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'asistio')
            ->assertJsonPath('data.pack.used', 1)
            ->assertJsonPath('data.charge', null);
        // Idempotente.
        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$id}/attendance", ['attended' => true])->assertJsonPath('data.pack.used', 1);

        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$id}/attendance", ['attended' => false])
            ->assertJsonPath('data.status', 'ausente')
            ->assertJsonPath('data.pack.used', 0);
        expect($pack->fresh()->used)->toBe(0);
    });

    it('la última clase termina el paquete y la anteúltima avisa', function () {
        $pack = paidPack();
        $pack->update(['used' => 2]);
        $this->travelTo(CarbonImmutable::parse('2026-09-28 11:00', 'America/Asuncion'));
        $first = book($this->adult, $this->laura, date: '2026-09-28', time: '15:00')->json('data.id');
        $second = book($this->adult, $this->laura, date: '2026-09-28', time: '16:00')->json('data.id');

        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$first}/attendance", ['attended' => true]);
        Notification::assertSentTo($this->adult, PackLow::class);

        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$second}/attendance", ['attended' => true]);
        expect($pack->fresh()->status)->toBe(ClassPackStatus::Finished);

        // Corregir la última vuelve a activarlo.
        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$second}/attendance", ['attended' => false]);
        expect($pack->fresh())->status->toBe(ClassPackStatus::Active)->used->toBe(3);
    });

    it('después del vencimiento la reserva es suelta; vence, avisa una vez y se extiende', function () {
        $pack = paidPack();
        $pack->update(['expires_on' => '2026-10-02']);

        book($this->adult, $this->laura, date: '2026-10-05', time: '15:00')->assertJsonPath('data.payment', 'suelta');
        book($this->adult, $this->laura, date: '2026-10-01', time: '15:00')->assertJsonPath('data.payment', 'paquete');

        // Faltan 4 días: aviso de "vence pronto" (le quedan 3 sin reservar). Una sola vez.
        app(ExpireClassPacks::class)->handle($this->org);
        app(ExpireClassPacks::class)->handle($this->org);
        Notification::assertSentToTimes($this->adult, PackExpiring::class, 1);
        Notification::assertSentTo($this->adult, PackExpiring::class, fn (PackExpiring $n) => str_contains($n->body, 'le quedan 3 clases sin reservar'));

        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:00', 'America/Asuncion'));
        expect(app(ExpireClassPacks::class)->handle($this->org))->toBe(['expired' => 1, 'warned' => 0])
            ->and($pack->fresh()->status)->toBe(ClassPackStatus::Expired);

        lessonsApi($this->adult, 'GET', 'lessons/teachers')->assertJsonPath('data.0.students.0.pack.status', 'vencido');

        lessonsApi($this->teacher, 'POST', "teacher/packs/{$pack->id}/extend", ['expires_on' => '2026-10-01'])->assertUnprocessable();
        lessonsApi($this->teacher, 'POST', "teacher/packs/{$pack->id}/extend", ['expires_on' => '2026-10-17'])
            ->assertOk()
            ->assertJsonPath('data.status', 'activo')
            ->assertJsonPath('data.expires_on', '2026-10-17');
        expect(Activity::query()->where('log_name', 'billing')->where('subject_type', $pack->getMorphClass())->where('subject_id', $pack->id)->where('description', 'updated')->exists())->toBeTrue();
    });
});

describe('clase suelta', function () {
    it('"Vino" emite el cargo una vez, se cobra y "No vino" no se cobra', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-28 11:00', 'America/Asuncion'));
        $id = book($this->tutor, $this->mateo, date: '2026-09-28', time: '15:00')->json('data.id');

        // El mismo día de la clase ya se puede marcar.
        $marked = lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$id}/attendance", ['attended' => true])
            ->assertJsonPath('data.status', 'asistio')
            ->assertJsonPath('data.charge.amount', 35000)
            ->assertJsonPath('data.charge.pending', 35000);
        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$id}/attendance", ['attended' => true]);
        expect(Charge::query()->where('student_id', $this->mateo->id)->count())->toBe(1)
            ->and(Charge::query()->findOrFail($marked->json('data.charge.id'))->description)->toBe('Clase particular 28/09 con Carlos Gómez');

        lessonsApi($this->teacher, 'GET', 'teacher/students')
            ->assertJsonPath('data.0.student.first_name', 'Mateo')
            ->assertJsonPath('data.0.debt', 35000);

        lessonsApi($this->teacher, 'POST', 'teacher/payments', ['student_id' => $this->mateo->id, 'amount' => 35000, 'method' => 'efectivo', 'booking_id' => $id])
            ->assertCreated()
            ->assertJsonPath('data.booking.charge.pending', 0)
            ->assertJsonPath('data.receipt_number', '000001');

        // Ya cobrada: no se corrige a "No vino" desde la app.
        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$id}/attendance", ['attended' => false])
            ->assertUnprocessable()
            ->assertJsonPath('errors.attended.0', 'Ya está cobrada: anulá el pago desde el panel.');
    });

    it('el pago antes de la clase queda a favor y se aplica al marcar "Vino"', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-28 11:00', 'America/Asuncion'));
        $id = book($this->adult, $this->laura, date: '2026-09-28', time: '15:00')->json('data.id');

        lessonsApi($this->teacher, 'POST', 'teacher/payments', ['student_id' => $this->laura->id, 'amount' => 35000, 'method' => 'efectivo', 'booking_id' => $id])
            ->assertJsonPath('data.credit', 35000);
        lessonsApi($this->teacher, 'GET', 'teacher/bookings')->assertJsonPath('data.0.credit', 35000);

        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$id}/attendance", ['attended' => true])
            ->assertJsonPath('data.charge.pending', 0)
            ->assertJsonPath('data.credit', 0);
    });

    it('"No vino" no cobra y la corrección de un cargo impago lo anula', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-28 11:00', 'America/Asuncion'));
        $id = book($this->adult, $this->laura, date: '2026-09-28', time: '15:00')->json('data.id');

        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$id}/attendance", ['attended' => false])
            ->assertJsonPath('data.status', 'ausente')
            ->assertJsonPath('data.charge', null);
        expect(Charge::query()->count())->toBe(0);

        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$id}/attendance", ['attended' => true]);
        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$id}/attendance", ['attended' => false])->assertJsonPath('data.charge', null);
        expect(Charge::query()->sole())->isVoided()->toBeTrue()->void_reason->toBe('Marcado como ausente');

        // Volver a "Vino" emite un cargo nuevo.
        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$id}/attendance", ['attended' => true])->assertJsonPath('data.charge.pending', 35000);
    });

    it('una clase futura no se marca y no se cobra a alumnos ajenos', function () {
        $id = book($this->adult, $this->laura, date: '2026-10-01')->json('data.id');
        lessonsApi($this->teacher, 'PUT', "teacher/bookings/{$id}/attendance", ['attended' => true])->assertUnprocessable();

        lessonsApi($this->teacher, 'POST', 'teacher/payments', ['student_id' => $this->mateo->id, 'amount' => 35000, 'method' => 'efectivo'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.student_id.0', 'Solo podés cobrar o vender a tus alumnos.');
        lessonsApi($this->adult, 'GET', 'teacher/bookings')->assertForbidden();
    });
});

describe('ajustes del profesor', function () {
    it('guarda precios, paquetes y disponibilidad', function () {
        lessonsApi($this->teacher, 'GET', 'me/lesson-profile')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.money_accounts.0.name', 'Caja')
            ->assertJsonCount(5, 'data.availability');

        lessonsApi($this->teacher, 'PUT', 'me/lesson-profile', [
            'enabled' => true, 'duration_minutes' => 45, 'single_price' => 40000, 'min_notice_minutes' => 60, 'days_ahead' => 14,
            'money_account_id' => null,
            'packs' => [['classes' => 8, 'price' => 280000, 'valid_days' => null]],
            'availability' => [['weekday' => 6, 'starts_at' => '09:00', 'ends_at' => '12:00']],
        ])
            ->assertOk()
            ->assertJsonPath('data.single_price', 40000)
            ->assertJsonPath('data.packs.0.classes', 8)
            ->assertJsonPath('data.packs.0.valid_days', null)
            ->assertJsonPath('data.availability', [['weekday' => 6, 'starts_at' => '09:00', 'ends_at' => '12:00']]);

        // El paquete anterior deja de ofrecerse.
        expect($this->offer->fresh()->is_active)->toBeFalse();
        lessonsApi($this->adult, 'GET', 'lessons/teachers')->assertJsonCount(1, 'data.0.packs');
    });

    it('rechaza franjas superpuestas y la validez fuera de rango', function () {
        $base = ['enabled' => true, 'duration_minutes' => 60, 'single_price' => 35000, 'min_notice_minutes' => 120, 'days_ahead' => 30, 'packs' => []];

        lessonsApi($this->teacher, 'PUT', 'me/lesson-profile', [...$base, 'availability' => [
            ['weekday' => 1, 'starts_at' => '15:00', 'ends_at' => '18:00'],
            ['weekday' => 1, 'starts_at' => '17:00', 'ends_at' => '19:00'],
        ]])->assertUnprocessable()->assertJsonPath('errors.availability.0', 'El lunes hay franjas que se superponen.');

        lessonsApi($this->teacher, 'PUT', 'me/lesson-profile', [...$base, 'availability' => [], 'packs' => [['classes' => 4, 'price' => 1000, 'valid_days' => 400]]])
            ->assertUnprocessable()->assertJsonValidationErrors('packs.0.valid_days');

        lessonsApi($this->tutor, 'GET', 'me/lesson-profile')->assertForbidden();
    });
});

describe('avisos', function () {
    it('recuerda la clase al alumno y el resumen del día al profesor, una sola vez', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-28 09:00', 'America/Asuncion'));
        book($this->adult, $this->laura, date: '2026-09-28', time: '15:00');
        book($this->tutor, $this->mateo, date: '2026-09-28', time: '17:00');

        // 3 h antes para el alumno (por defecto del club) y 2 h antes de la primera para el profesor.
        $remind = app(SendClassReminders::class);
        expect($remind->handle($this->org, CarbonImmutable::parse('2026-09-28 11:00', 'America/Asuncion')))->toBe(0);
        $remind->handle($this->org, CarbonImmutable::parse('2026-09-28 13:00', 'America/Asuncion'));
        $remind->handle($this->org, CarbonImmutable::parse('2026-09-28 13:15', 'America/Asuncion'));

        Notification::assertSentToTimes($this->adult, LessonReminder::class, 1);
        Notification::assertSentTo($this->adult, LessonReminder::class, fn (LessonReminder $n) => $n->body === 'Hoy tenés clase con Carlos Gómez a las 15:00.');
        Notification::assertSentToTimes($this->teacher, TeacherDayReminder::class, 1);
        Notification::assertSentTo($this->teacher, TeacherDayReminder::class, fn (TeacherDayReminder $n) => $n->body === 'Hoy tenés 2 clases: 15:00 Laura, 17:00 Mateo.');

        $remind->handle($this->org, CarbonImmutable::parse('2026-09-28 14:00', 'America/Asuncion'));
        Notification::assertSentTo($this->tutor, LessonReminder::class, fn (LessonReminder $n) => $n->body === 'Hoy Mateo tiene clase con Carlos Gómez a las 17:00.');
    });

    it('el profesor ve sus avisos en la configuración', function () {
        lessonsApi($this->teacher, 'GET', 'me/notification-settings')->assertJsonPath('data.instructor.enabled', true);
    });
});

describe('panel', function () {
    it('el admin ve reservas, paquetes y profesores; sin el módulo no aparecen', function () {
        $admin = memberOf($this->org);
        app(RoleAssigner::class)->assign($this->org, $admin, OrganizationRole::Admin);
        book($this->adult, $this->laura)->assertCreated();
        paidPack();

        $this->actingAs($admin, 'web');
        $this->get('/admin/profe/reservas')->assertOk()->assertSee('Laura Ríos');
        $this->get('/admin/profe/paquetes-de-clases')->assertOk()->assertSee('Usó 0 de 4');
        $this->get('/admin/profe/profesores-particulares')->assertOk()->assertSee('Carlos Gómez');

        $this->org->update(['features' => []]);
        $this->get('/admin/profe/reservas')->assertForbidden();
    });

    it('el admin cancela una reserva y extiende un paquete', function () {
        $admin = memberOf($this->org);
        app(RoleAssigner::class)->assign($this->org, $admin, OrganizationRole::Admin);
        $id = book($this->adult, $this->laura)->json('data.id');
        $pack = paidPack();

        $this->actingAs($admin, 'web');
        filament()->setTenant($this->org);
        app(CurrentOrganization::class)->set($this->org);
        Livewire::test(ListBookings::class)
            ->callTableAction('cancel', Booking::query()->findOrFail($id), data: ['reason' => 'Feriado'])
            ->assertHasNoTableActionErrors();
        expect(Booking::query()->findOrFail($id))->status->toBe(BookingStatus::CancelledByTeacher)->cancel_reason->toBe('Feriado');

        Livewire::test(ListClassPacks::class)
            ->callTableAction('extend', $pack, data: ['expires_on' => '2026-12-31'])
            ->assertHasNoTableActionErrors();
        expect($pack->fresh()->expires_on->toDateString())->toBe('2026-12-31');
    });

    it('el admin edita precios, paquetes y disponibilidad del profesor', function () {
        $admin = memberOf($this->org);
        app(RoleAssigner::class)->assign($this->org, $admin, OrganizationRole::Admin);
        $this->actingAs($admin, 'web');
        filament()->setTenant($this->org);
        app(CurrentOrganization::class)->set($this->org);

        Livewire::test(ManageLessonProfiles::class)
            ->mountTableAction('edit', $this->profile)
            ->assertTableActionDataSet(['single_price' => 35000, 'enabled' => true])
            ->setTableActionData(['single_price' => 40000, 'packs' => [['classes' => 8, 'price' => 280000, 'valid_days' => 30]], 'availability' => [['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00']]])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        expect($this->profile->fresh()->single_price)->toBe(40000)
            ->and(LessonPack::query()->where('user_id', $this->teacher->id)->sole())->classes->toBe(8)->organization_id->toBe($this->org->id)
            ->and(AvailabilitySlot::query()->where('user_id', $this->teacher->id)->sole())->weekday->toBe(2);
    });
});
