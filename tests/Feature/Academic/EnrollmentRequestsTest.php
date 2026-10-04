<?php

use App\Enums\EnrollmentRequestStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\EnrollmentRequests\Pages\ManageEnrollmentRequests;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\EnrollmentRequest;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Invitation;
use App\Models\MedicalRecord;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Models\User;
use App\Notifications\EnrollmentRequested;
use App\Notifications\EnrollmentRequestReviewed;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // Domingo 04/10/2026.
    $this->travelTo('2026-10-04 12:00:00');
    Notification::fake();
    Mail::fake();

    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    app(CurrentOrganization::class)->set($this->jakare);

    $this->season = Season::factory()->for($this->jakare)->monthly()->create(['name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30']);
    $this->futbol = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $this->sub8 = Group::factory()->for($this->futbol)->create(['name' => 'Sub-8', 'organization_id' => $this->jakare->id, 'min_age' => 7, 'max_age' => 8, 'capacity' => 20]);
    $this->sub10 = Group::factory()->for($this->futbol)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id, 'min_age' => 9, 'max_age' => 10]);
    Schedule::factory()->create(['group_id' => $this->sub8->id, 'weekday' => 1, 'starts_at' => '17:00', 'ends_at' => '18:30']);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $this->season->id, 'amount' => 150000, 'valid_from' => '2026-02-01']);

    // Ana ya es tutora de Mateo (Sub-10).
    $this->tutor = memberOf($this->jakare, ['name' => 'Ana Benítez']);
    app(RoleAssigner::class)->assign($this->jakare, $this->tutor, OrganizationRole::Guardian);
    $this->family = Family::factory()->for($this->jakare)->create(['name' => 'Familia Benítez']);
    $this->guardian = Guardian::factory()->for($this->jakare)->create(['user_id' => $this->tutor->id, 'family_id' => $this->family->id, 'first_name' => 'Ana María', 'last_name' => 'Benítez']);
    $this->mateo = Student::factory()->for($this->jakare)->create(['first_name' => 'Mateo', 'last_name' => 'Benítez', 'document' => '6123456', 'birth_date' => '2016-03-14', 'family_id' => $this->family->id]);
    $this->guardian->students()->attach($this->mateo->id, ['relationship' => 'madre']);
    $this->mateoEnrollment = Enrollment::factory()->create(['student_id' => $this->mateo->id, 'group_id' => $this->sub10->id, 'season_id' => $this->season->id, 'status' => EnrollmentStatus::Active]);

    $this->secretary = memberOf($this->jakare, ['name' => 'Laura Gómez']);
    app(RoleAssigner::class)->assign($this->jakare, $this->secretary, OrganizationRole::Secretary, endsOn: now()->addYear());

    // Técnico de Sub-8.
    $this->coach = memberOf($this->jakare, ['name' => 'Carlos Gómez']);
    app(RoleAssigner::class)->assign($this->jakare, $this->coach, OrganizationRole::Instructor);
    $this->sub8->instructors()->attach($this->coach);
});

function sofia(array $data = []): array
{
    return [
        'first_name' => 'Sofía',
        'last_name' => 'Benítez',
        'birth_date' => '2018-07-02',
        'document' => '7123456',
        'relationship' => 'madre',
        'season_id' => test()->season->id,
        'group_id' => test()->sub8->id,
        ...$data,
    ];
}

function asMember(User $user, string $method, string $uri, array $data = [])
{
    return test()->actingAs($user, 'sanctum')->json($method, "/api/v1/{$uri}", $data, ['X-Organization' => 'jakare']);
}

function requestFor(User $user, array $data = []): EnrollmentRequest
{
    $id = asMember($user, 'POST', 'enrollment-requests', sofia($data))->assertCreated()->json('data.id');

    return EnrollmentRequest::query()->findOrFail($id);
}

/**
 * Los alumnos de la clase del lunes 05/10 de Sub-8, como los ve el técnico.
 *
 * @return list<array<string, mixed>>
 */
function mondayClass(User $coach): array
{
    $id = asMember($coach, 'GET', 'classes?date=2026-10-05')->assertOk()->json('data.0.id');

    return asMember($coach, 'GET', "classes/{$id}")->assertOk()->json('data.students');
}

describe('tutor', function () {
    it('ve dónde inscribir con la categoría sugerida por edad y el cupo', function () {
        Group::factory()->for($this->futbol)->create(['name' => 'Sub-12', 'organization_id' => $this->jakare->id, 'is_active' => false]);
        $padel = Program::factory()->for($this->jakare)->create(['name' => 'Pádel']);
        $inicial = Group::factory()->for($padel)->create(['name' => 'Inicial', 'organization_id' => $this->jakare->id, 'min_age' => null, 'max_age' => null]);
        $colonia = Season::factory()->for($this->jakare)->create(['name' => 'Colonia', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-31']);
        $colonia->programs()->attach($padel);
        Season::factory()->for($this->jakare)->create(['name' => '2025', 'starts_on' => '2025-02-01', 'ends_on' => '2025-11-30']);

        $options = asMember($this->tutor, 'GET', 'enrollment-requests/options?birth_date=2018-07-02')->assertOk()->json('data');

        expect(collect($options)->map(fn ($o) => "{$o['program']['name']} · {$o['season']['name']}")->all())
            ->toBe(['Fútbol · 2026', 'Pádel · 2026', 'Pádel · Colonia']);

        $futbol = $options[0];
        expect($futbol['suggested_group_id'])->toBe($this->sub8->id)
            ->and(collect($futbol['groups'])->pluck('name')->all())->toBe(['Sub-10', 'Sub-8'])
            ->and($futbol['season'])->toBe(['id' => $this->season->id, 'name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30']);
        $sub8 = collect($futbol['groups'])->firstWhere('id', $this->sub8->id);
        expect($sub8['capacity'])->toBe(20)->and($sub8['spots_left'])->toBe(20)->and($sub8['full'])->toBeFalse()
            ->and($sub8['schedules'])->toBe([['weekday' => 1, 'starts_at' => '17:00', 'ends_at' => '18:30']]);
        expect($options[2]['suggested_group_id'])->toBeNull()
            ->and($options[2]['groups'][0]['id'])->toBe($inicial->id);
    });

    it('pide la inscripción: entra ya a la lista del técnico, sin cuotas, y avisa a quienes confirman', function () {
        $charges = Charge::query()->count();

        asMember($this->tutor, 'POST', 'enrollment-requests', sofia([
            'notes' => 'Es su primer año.',
            'medical' => ['blood_type' => 'O+', 'allergies' => 'Penicilina', 'conditions' => ''],
        ]))->assertCreated()
            ->assertJsonPath('data.status', 'pendiente')
            ->assertJsonPath('data.status_label', 'Por confirmar')
            ->assertJsonPath('data.child.full_name', 'Sofía Benítez')
            ->assertJsonPath('data.child.document', '7123456')
            ->assertJsonPath('data.relationship', 'madre')
            ->assertJsonPath('data.has_medical', true)
            ->assertJsonPath('data.group.name', 'Sub-8')
            ->assertJsonPath('data.reviewed_by', null)
            ->assertJsonMissingPath('data.medical');

        $request = EnrollmentRequest::query()->sole();
        $sofia = Student::query()->where('document', '7123456')->sole();
        expect($request->medical)->toBe(['blood_type' => 'O+', 'allergies' => 'Penicilina'])
            ->and($request->student_id)->toBe($sofia->id)
            ->and($sofia->family_id)->toBe($this->family->id)
            ->and($sofia->enrollments()->sole()->status)->toBe(EnrollmentStatus::Pending)
            ->and(Charge::query()->count())->toBe($charges)
            ->and(MedicalRecord::query()->count())->toBe(0);

        // La tutora la ve en "Mis hijos" (pendiente) y el técnico, en la clase, para tomarle asistencia.
        asMember($this->tutor, 'GET', 'students')->assertJsonCount(2, 'data');
        $student = collect(mondayClass($this->coach))->firstWhere('id', $sofia->id);
        expect($student['enrollment_request'])->toBe(['id' => $request->id, 'can_review' => true]);

        Notification::assertSentTo([$this->secretary, $this->coach], EnrollmentRequested::class,
            fn (EnrollmentRequested $n) => $n->body === 'Ana Benítez quiere inscribir a Sofía Benítez (8 años) en Sub-8 · Fútbol.');
        Notification::assertNotSentTo($this->tutor, EnrollmentRequested::class);
    });

    it('pide el documento y valida la temporada y la categoría', function () {
        asMember($this->tutor, 'POST', 'enrollment-requests', sofia(['document' => '', 'birth_date' => '2026-10-04']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document' => 'Ingresá el número de documento.', 'birth_date' => 'La fecha de nacimiento tiene que ser pasada.']);

        $past = Season::factory()->for($this->jakare)->create(['name' => '2025', 'starts_on' => '2025-02-01', 'ends_on' => '2025-11-30']);
        asMember($this->tutor, 'POST', 'enrollment-requests', sofia(['season_id' => $past->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['season_id' => 'Elegí una temporada vigente o próxima.']);
    });

    it('no repite el documento ni pide lo que ya tiene', function () {
        requestFor($this->tutor);

        asMember($this->tutor, 'POST', 'enrollment-requests', sofia(['first_name' => 'Sofi']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document' => 'Ya mandaste una solicitud para Sofi; esperá a que el club la confirme.']);
        asMember(memberOf($this->jakare), 'POST', 'enrollment-requests', sofia())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document' => 'Ya hay una solicitud por confirmar con ese documento.']);

        asMember($this->tutor, 'POST', 'enrollment-requests', sofia([
            'first_name' => 'Mateo', 'birth_date' => '2016-03-14', 'document' => '6123456', 'group_id' => $this->sub10->id,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['document' => 'Ese documento ya tiene inscripción en Sub-10 (2026). Consultá con el club.']);
    });

    it('cancelar la saca de la lista y borra el alta', function () {
        $pending = requestFor($this->tutor, ['medical' => ['allergies' => 'Penicilina']]);
        $foreign = requestFor(memberOf($this->jakare), ['first_name' => 'Lucas', 'document' => '999']);

        asMember($this->tutor, 'GET', 'enrollment-requests')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id);

        asMember($this->tutor, 'DELETE', "enrollment-requests/{$foreign->id}")->assertNotFound();
        asMember($this->tutor, 'DELETE', "enrollment-requests/{$pending->id}")->assertNoContent();

        expect($pending->fresh()->status)->toBe(EnrollmentRequestStatus::Cancelled)
            ->and($pending->fresh()->medical)->toBeNull()
            ->and(Student::query()->where('document', '7123456')->exists())->toBeFalse();
        expect(collect(mondayClass($this->coach))->pluck('full_name')->all())->toBe(['Lucas Benítez']);
        asMember($this->tutor, 'GET', 'enrollment-requests')->assertJsonCount(0, 'data');
        asMember($this->tutor, 'DELETE', "enrollment-requests/{$pending->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'Esta solicitud ya fue revisada.']);
    });

    it('no confirma ni tiene el permiso en la organización', function () {
        asMember($this->tutor, 'GET', 'enrollment-requests/review')->assertForbidden();
        $request = requestFor($this->tutor);
        asMember($this->tutor, 'POST', "enrollment-requests/{$request->id}/approve")->assertForbidden();

        $permissions = fn (User $user) => asMember($user, 'GET', 'organization')->json('data.membership.permissions');
        expect($permissions($this->tutor))->not->toContain('manage_enrollment_requests')
            ->and($permissions($this->secretary))->toContain('manage_enrollment_requests', 'create_students')
            ->and($permissions($this->coach))->toContain('manage_enrollment_requests')
            ->and($permissions($this->coach))->not->toContain('create_students');
    });
});

describe('quien confirma', function () {
    it('lista las pendientes con quién la pidió, el cupo y qué se cobra del mes', function () {
        $this->tutor->update(['phone' => '+595981123456']);
        requestFor($this->tutor);

        $data = asMember($this->secretary, 'GET', 'enrollment-requests/review')->assertOk()->json('data.0');

        expect($data['requested_by'])->toBe(['name' => 'Ana Benítez', 'phone' => '0981 123 456', 'email' => $this->tutor->email])
            ->and($data['age'])->toBe(8)
            ->and($data['existing_student'])->toBeNull()
            ->and(collect($data['group_options'])->firstWhere('suggested', true))->toMatchArray(['id' => $this->sub8->id, 'spots_left' => 20])
            ->and($data['mid_period']['label'])->toBe('Se inscribe a mitad de mes: se cobra')
            ->and($data['mid_period']['default'])->toBe('completo')
            ->and(collect($data['mid_period']['options'])->pluck('value')->all())->toBe(['completo', 'proporcional', 'proximo']);
    });

    it('confirmar emite las cuotas, pasa la ficha médica y queda quién la confirmó', function () {
        $request = requestFor($this->tutor, ['medical' => ['blood_type' => 'O+', 'allergies' => 'Penicilina']]);

        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve")->assertOk()
            ->assertJsonPath('data.status', 'aprobada')
            ->assertJsonPath('data.reviewed_by', 'Laura Gómez')
            ->assertJsonPath('data.self_approved', false);

        $sofia = Student::query()->where('first_name', 'Sofía')->sole();
        expect($sofia->guardians()->sole()->id)->toBe($this->guardian->id)
            ->and($this->guardian->fresh()->first_name)->toBe('Ana María')
            ->and(Guardian::query()->count())->toBe(1)
            ->and($sofia->enrollments()->sole()->status)->toBe(EnrollmentStatus::Active)
            ->and(Charge::query()->where('student_id', $sofia->id)->sole()->final_amount)->toBe(150000)
            ->and(MedicalRecord::query()->where('student_id', $sofia->id)->sole()->allergies)->toBe('Penicilina')
            ->and($request->fresh()->medical)->toBeNull()
            ->and($request->fresh()->reviewed_by)->toBe($this->secretary->id);

        Notification::assertSentTo($this->tutor, EnrollmentRequestReviewed::class, fn (EnrollmentRequestReviewed $n) => $n->approved
            && $n->body === 'Aprobamos la inscripción de Sofía en Sub-8 · Fútbol (2026). Ya ves sus clases y sus cuotas en la app.'
            && $n->toPush($this->tutor)->data['route'] === "/hijos/{$sofia->id}");

        expect(collect(mondayClass($this->coach))->firstWhere('id', $sofia->id)['enrollment_request'])->toBeNull();
    });

    it('quien elige "desde el mes que viene" no paga octubre', function () {
        $request = requestFor($this->tutor);

        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve", ['mid_period' => 'proximo'])->assertOk();

        expect(Charge::query()->where('student_id', $request->student_id)->count())->toBe(0);
    });

    it('vuelve después de una baja: no se cobran los meses que estuvo afuera', function () {
        // Tomás jugó de febrero a mayo en Sub-10 y se dio de baja.
        $tomas = Student::factory()->for($this->jakare)->create(['first_name' => 'Tomás', 'last_name' => 'Benítez', 'document' => '6555444', 'birth_date' => '2016-08-01', 'family_id' => $this->family->id]);
        $this->guardian->students()->attach($tomas->id, ['relationship' => 'madre']);
        $enrollment = Enrollment::withoutSeasonCharges(fn () => Enrollment::factory()->create([
            'student_id' => $tomas->id, 'group_id' => $this->sub10->id, 'season_id' => $this->season->id,
            'status' => EnrollmentStatus::Active, 'enrolled_on' => '2026-02-01',
        ]));
        foreach (['2026-02', '2026-03', '2026-04', '2026-05'] as $month) {
            issueMonth($this->jakare, $month);
        }
        $enrollment->update(['status' => EnrollmentStatus::Withdrawn]);

        $request = requestFor($this->tutor, ['first_name' => 'Tomás', 'birth_date' => '2016-08-01', 'document' => '6555444', 'group_id' => $this->sub10->id]);

        expect($request->enrollment_id)->toBe($enrollment->id)
            ->and($enrollment->fresh()->status)->toBe(EnrollmentStatus::Pending)
            ->and($enrollment->fresh()->enrolled_on->toDateString())->toBe('2026-10-04');

        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve")->assertOk();

        expect(Charge::query()->where('student_id', $tomas->id)->whereNull('voided_at')->orderBy('period')->get()
            ->map(fn (Charge $charge) => CarbonImmutable::parse($charge->period)->format('Y-m'))->all())
            ->toBe(['2026-02', '2026-03', '2026-04', '2026-05', '2026-10']);
    });

    it('un miembro sin ficha de tutor queda como tutor de su hijo', function () {
        $member = memberOf($this->jakare, ['name' => 'Pedro Gómez Vera', 'phone' => '+595981555444', 'phone_verified_at' => now()]);
        $request = requestFor($member, ['relationship' => 'padre', 'document' => '8000111']);

        $guardian = Guardian::query()->where('user_id', $member->id)->sole();
        expect($guardian->first_name)->toBe('Pedro')
            ->and($guardian->last_name)->toBe('Gómez Vera')
            ->and($guardian->phone)->toBe('+595981555444')
            ->and($guardian->students()->sole()->pivot->relationship)->toBe('padre');

        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve")->assertOk();

        expect($member->fresh()->hasCurrentRole($this->jakare, OrganizationRole::Guardian))->toBeTrue();
        asMember($member, 'GET', 'students')->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Sofía');
    });

    it('al chico de otra familia el tutor se suma recién al confirmar', function () {
        $sofia = Student::factory()->for($this->jakare)->create(['first_name' => 'Sofía', 'last_name' => 'Benítez', 'document' => '7123456', 'birth_date' => '2018-07-02']);
        $father = Guardian::factory()->for($this->jakare)->create(['first_name' => 'Carlos', 'last_name' => 'Benítez']);
        $father->students()->attach($sofia->id, ['relationship' => 'padre']);
        $request = requestFor($this->tutor, ['first_name' => 'Sofi']);

        // Ya va a clases, pero Ana todavía no ve sus datos (ni le cambió el nombre).
        expect($request->student_id)->toBe($sofia->id)
            ->and($sofia->fresh()->first_name)->toBe('Sofía');
        asMember($this->tutor, 'GET', 'students')->assertJsonCount(1, 'data');
        expect(collect(mondayClass($this->coach))->pluck('id'))->toContain($sofia->id);

        asMember($this->secretary, 'GET', 'enrollment-requests/review')
            ->assertJsonPath('data.0.existing_student.id', $sofia->id)
            ->assertJsonPath('data.0.existing_student.guardians', ['Carlos Benítez']);

        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve")->assertOk();

        expect(Student::query()->count())->toBe(2)
            ->and($sofia->guardians()->pluck('guardians.id')->sort()->values()->all())->toBe(collect([$father->id, $this->guardian->id])->sort()->values()->all());
        asMember($this->tutor, 'GET', 'students')->assertJsonCount(2, 'data');
    });

    it('pide confirmar el cupo lleno (sin contar su propio lugar) y la categoría es de la disciplina', function () {
        $this->sub8->update(['capacity' => 1]);
        $request = requestFor($this->tutor);

        // Su lugar ya está tomado por ella misma: se confirma sin más.
        asMember($this->secretary, 'GET', 'enrollment-requests/review')->assertJsonPath('data.0.group_options.1.full', false);

        Enrollment::factory()->create(['group_id' => $this->sub8->id, 'season_id' => $this->season->id, 'status' => EnrollmentStatus::Active,
            'student_id' => Student::factory()->for($this->jakare)->create()->id]);
        $padel = Group::factory()->for(Program::factory()->for($this->jakare))->create(['organization_id' => $this->jakare->id]);

        asMember($this->tutor, 'GET', 'enrollment-requests/options')
            ->assertJsonPath('data.0.groups.1.full', true)->assertJsonPath('data.0.groups.1.spots_left', 0);
        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['over_capacity' => 'Sub-8 está completa (1 de 1). Confirmá para inscribirla igual.']);
        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve", ['group_id' => $padel->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['group_id' => 'Elegí una categoría de Fútbol.']);

        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve", ['over_capacity' => true])->assertOk()
            ->assertJsonPath('data.group.name', 'Sub-8');
    });

    it('confirmar en otra categoría y dos veces no duplica nada', function () {
        $request = requestFor($this->tutor);

        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve", ['group_id' => $this->sub10->id])->assertOk()
            ->assertJsonPath('data.group.name', 'Sub-10');
        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'Esta solicitud ya fue revisada.']);

        $enrollment = Student::query()->where('first_name', 'Sofía')->sole()->enrollments()->sole();
        expect($enrollment->group_id)->toBe($this->sub10->id)->and($enrollment->status)->toBe(EnrollmentStatus::Active);
    });

    it('rechazar la saca de la lista, pide el motivo y avisa al tutor', function () {
        $request = requestFor($this->tutor, ['medical' => ['allergies' => 'Penicilina']]);

        asMember($this->coach, 'POST', "enrollment-requests/{$request->id}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'Contale a la familia por qué no la aprobás.']);
        asMember($this->coach, 'POST', "enrollment-requests/{$request->id}/reject", ['reason' => 'No hay lugar en Sub-8 este año.'])->assertOk()
            ->assertJsonPath('data.status', 'rechazada')
            ->assertJsonPath('data.status_label', 'No aprobada')
            ->assertJsonPath('data.reviewed_by', 'Carlos Gómez')
            ->assertJsonPath('data.rejection_reason', 'No hay lugar en Sub-8 este año.');

        expect($request->fresh()->medical)->toBeNull()
            ->and($request->fresh()->reviewed_by)->toBe($this->coach->id)
            ->and(Student::query()->where('document', '7123456')->exists())->toBeFalse()
            ->and(collect(mondayClass($this->coach)))->toBeEmpty();
        Notification::assertSentTo($this->tutor, EnrollmentRequestReviewed::class, fn (EnrollmentRequestReviewed $n) => ! $n->approved
            && $n->body === 'El club no aprobó la inscripción de Sofía: No hay lugar en Sub-8 este año.');

        asMember($this->tutor, 'GET', 'enrollment-requests')->assertJsonPath('data.0.rejection_reason', 'No hay lugar en Sub-8 este año.');
    });

    it('el técnico confirma solo en sus categorías', function () {
        $sub8 = requestFor($this->tutor);
        $sub10 = requestFor($this->tutor, ['first_name' => 'Lucía', 'document' => '7999888', 'group_id' => $this->sub10->id]);

        asMember($this->coach, 'GET', 'enrollment-requests/review')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sub8->id);
        asMember($this->coach, 'POST', "enrollment-requests/{$sub10->id}/approve")->assertNotFound();
        asMember($this->coach, 'POST', "enrollment-requests/{$sub8->id}/approve", ['group_id' => $this->sub10->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['group_id' => 'Elegí una de las categorías que podés confirmar.']);

        asMember($this->coach, 'POST', "enrollment-requests/{$sub8->id}/approve")->assertOk()->assertJsonPath('data.reviewed_by', 'Carlos Gómez');
        Notification::assertNotSentTo($this->coach, EnrollmentRequested::class, fn (EnrollmentRequested $n) => str_contains($n->body, 'Lucía'));
    });

    it('sin el permiso por rol, el técnico no confirma', function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::query()->where('organization_id', $this->jakare->id)->where('name', 'instructor')->sole()
            ->revokePermissionTo('Confirm:GroupEnrollments');
        $request = requestFor($this->tutor);

        asMember($this->coach->fresh(), 'POST', "enrollment-requests/{$request->id}/approve")->assertForbidden();
        Notification::assertNotSentTo($this->coach, EnrollmentRequested::class);
    });

    it('si quien la pide puede confirmar, se confirma sola y queda registrado', function () {
        $charges = Charge::query()->count();

        asMember($this->secretary, 'POST', 'enrollment-requests', sofia(['relationship' => 'madre']))->assertCreated()
            ->assertJsonPath('data.status', 'aprobada')
            ->assertJsonPath('data.reviewed_by', 'Laura Gómez')
            ->assertJsonPath('data.self_approved', true);

        $request = EnrollmentRequest::query()->sole();
        expect($request->reviewed_by)->toBe($this->secretary->id)
            ->and($request->enrollment->status)->toBe(EnrollmentStatus::Active)
            ->and(Charge::query()->count())->toBe($charges + 1)
            ->and($this->secretary->fresh()->hasCurrentRole($this->jakare, OrganizationRole::Guardian))->toBeTrue();
        Notification::assertNothingSent();
    });

    it('con el permiso "Confirmar inscripciones" también confirma (editable por rol)', function () {
        $treasurer = memberOf($this->jakare);
        app(RoleAssigner::class)->assign($this->jakare, $treasurer, OrganizationRole::Treasurer, endsOn: now()->addYear());
        $request = requestFor($this->tutor);

        asMember($treasurer, 'GET', 'enrollment-requests/review')->assertForbidden();

        // La petición anterior dejó sanctum como guard por defecto y la caché de spatie cargada.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('Manage:EnrollmentRequests', 'web');
        Role::query()->where('organization_id', $this->jakare->id)->where('name', OrganizationRole::Treasurer->value)->sole()
            ->givePermissionTo('Manage:EnrollmentRequests');

        asMember($treasurer->fresh(), 'POST', "enrollment-requests/{$request->id}/approve")->assertOk();
    });

    it('no ve ni confirma las de otra organización', function () {
        $request = requestFor($this->tutor);
        $other = Organization::factory()->create(['slug' => 'otro']);
        $admin = memberOf($other);
        app(RoleAssigner::class)->assign($other, $admin, OrganizationRole::Admin);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/enrollment-requests/review', ['X-Organization' => 'otro'])
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/enrollment-requests/{$request->id}/approve", [], ['X-Organization' => 'otro'])
            ->assertNotFound();
        expect($request->fresh()->isPending())->toBeTrue();
    });
});

describe('cargar alumno desde la app', function () {
    function newStudent(array $data = []): array
    {
        return [
            ...sofia(),
            'guardian' => ['first_name' => 'Rosa', 'last_name' => 'Aquino', 'phone' => '0981 222 333', 'relationship' => 'madre'],
            ...$data,
        ];
    }

    it('da de alta al alumno con su cuota y deja la invitación del tutor para WhatsApp', function () {
        $response = asMember($this->secretary, 'POST', 'students', newStudent())->assertCreated()
            ->assertJsonPath('data.student.full_name', 'Sofía Benítez')
            ->assertJsonPath('data.student.place', 'Sub-8 · Fútbol (2026)')
            ->assertJsonPath('data.guardian.name', 'Rosa Aquino')
            ->assertJsonPath('data.guardian.has_account', false);

        $sofia = Student::query()->where('document', '7123456')->sole();
        expect($sofia->enrollments()->sole()->status)->toBe(EnrollmentStatus::Active)
            ->and(Charge::query()->where('student_id', $sofia->id)->count())->toBe(1)
            ->and($sofia->guardians()->sole()->phone)->toBe('+595981222333');

        $invitation = Invitation::query()->sole();
        expect($invitation->guardian_id)->toBe($sofia->guardians()->sole()->id)
            ->and($response->json('data.invitation.link'))->toContain('/invitacion/')
            ->and($response->json('data.invitation.whatsapp_url'))->toStartWith('https://wa.me/595981222333');
    });

    it('si el tutor ya usa la app no hace falta invitarlo', function () {
        $this->guardian->update(['phone' => '0981 444 555']);

        asMember($this->secretary, 'POST', 'students', newStudent(['guardian' => ['first_name' => 'Ana', 'phone' => '0981444555']]))
            ->assertCreated()
            ->assertJsonPath('data.guardian.has_account', true)
            ->assertJsonPath('data.invitation', null);

        asMember($this->tutor, 'GET', 'students')->assertJsonCount(2, 'data');
    });

    it('valida el documento, el celular del tutor y que no esté cargado', function () {
        asMember($this->secretary, 'POST', 'students', newStudent(['document' => '', 'guardian' => ['first_name' => 'Rosa', 'phone' => '12']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document' => 'Ingresá el número de documento.']);
        asMember($this->secretary, 'POST', 'students', newStudent(['guardian' => ['first_name' => 'Rosa', 'phone' => '12']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guardian.phone' => 'Ingresá un celular válido.']);
        asMember($this->secretary, 'POST', 'students', newStudent(['document' => '6123456']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document']);
    });

    it('solo quien puede crear alumnos', function () {
        asMember($this->tutor, 'POST', 'students', newStudent())->assertForbidden();
        asMember($this->coach, 'POST', 'students', newStudent())->assertForbidden();
    });
});

describe('panel', function () {
    beforeEach(function () {
        $this->actingAs($this->secretary);
        filament()->setTenant($this->jakare);
    });

    it('lista las solicitudes, confirma y rechaza', function () {
        $first = requestFor($this->tutor);
        $second = requestFor($this->tutor, ['first_name' => 'Lucía', 'document' => '7999888']);
        $this->actingAs($this->secretary);

        $this->get('/admin/jakare/solicitudes-de-inscripcion')->assertOk()->assertSee('Sofía Benítez');

        Livewire::test(ManageEnrollmentRequests::class)
            ->assertCanSeeTableRecords([$first, $second])
            ->callTableAction('approve', $first, data: ['group_id' => $this->sub8->id, 'mid_period' => 'completo'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('reject', $second, data: ['reason' => 'Falta el certificado.'])
            ->assertHasNoTableActionErrors();

        expect($first->fresh()->status)->toBe(EnrollmentRequestStatus::Approved)
            ->and($first->fresh()->reviewed_by)->toBe($this->secretary->id)
            ->and($first->fresh()->enrollment->status)->toBe(EnrollmentStatus::Active)
            ->and($second->fresh()->status)->toBe(EnrollmentRequestStatus::Rejected);
    });

    it('un tutor no entra a las solicitudes', function () {
        $this->actingAs($this->tutor);

        $this->get('/admin/jakare/solicitudes-de-inscripcion')->assertForbidden();
    });
});
