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
use App\Models\MedicalRecord;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Role;
use App\Models\Season;
use App\Models\Student;
use App\Models\Tariff;
use App\Models\User;
use App\Notifications\EnrollmentRequested;
use App\Notifications\EnrollmentRequestReviewed;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->travelTo('2026-10-04 12:00:00');
    Notification::fake();

    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    app(CurrentOrganization::class)->set($this->jakare);

    $this->season = Season::factory()->for($this->jakare)->monthly()->create(['name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30']);
    $this->futbol = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $this->sub8 = Group::factory()->for($this->futbol)->create(['name' => 'Sub-8', 'organization_id' => $this->jakare->id, 'min_age' => 7, 'max_age' => 8, 'capacity' => 20]);
    $this->sub10 = Group::factory()->for($this->futbol)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id, 'min_age' => 9, 'max_age' => 10]);
    Tariff::factory()->create(['fee_concept_id' => FeeConcept::monthlyFee($this->jakare)->id, 'season_id' => $this->season->id, 'amount' => 150000, 'valid_from' => '2026-02-01']);

    // Ana ya es tutora de Mateo (Sub-10).
    $this->tutor = memberOf($this->jakare, ['name' => 'Ana Benítez']);
    app(RoleAssigner::class)->assign($this->jakare, $this->tutor, OrganizationRole::Guardian);
    $this->family = Family::factory()->for($this->jakare)->create(['name' => 'Familia Benítez']);
    $this->guardian = Guardian::factory()->for($this->jakare)->create(['user_id' => $this->tutor->id, 'family_id' => $this->family->id, 'first_name' => 'Ana María', 'last_name' => 'Benítez']);
    $this->mateo = Student::factory()->for($this->jakare)->create(['first_name' => 'Mateo', 'last_name' => 'Benítez', 'birth_date' => '2016-03-14', 'family_id' => $this->family->id]);
    $this->guardian->students()->attach($this->mateo->id, ['relationship' => 'madre']);
    Enrollment::factory()->create(['student_id' => $this->mateo->id, 'group_id' => $this->sub10->id, 'season_id' => $this->season->id, 'status' => EnrollmentStatus::Active]);

    $this->secretary = memberOf($this->jakare, ['name' => 'Laura Gómez']);
    app(RoleAssigner::class)->assign($this->jakare, $this->secretary, OrganizationRole::Secretary, endsOn: now()->addYear());
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

describe('tutor', function () {
    it('ve dónde inscribir con la categoría sugerida por edad y el cupo', function () {
        Group::factory()->for($this->futbol)->create(['name' => 'Sub-12', 'organization_id' => $this->jakare->id, 'is_active' => false]);
        $padel = Program::factory()->for($this->jakare)->create(['name' => 'Pádel']);
        $inicial = Group::factory()->for($padel)->create(['name' => 'Inicial', 'organization_id' => $this->jakare->id, 'min_age' => null, 'max_age' => null]);
        $colonia = Season::factory()->for($this->jakare)->create(['name' => 'Colonia', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-31']);
        $colonia->programs()->attach($padel);
        Season::factory()->for($this->jakare)->create(['name' => '2025', 'starts_on' => '2025-02-01', 'ends_on' => '2025-11-30']);

        $options = asMember($this->tutor, 'GET', 'enrollment-requests/options?birth_date=2018-07-02')->assertOk()->json('data');

        expect($options)->toHaveCount(3)
            ->and(collect($options)->map(fn ($o) => "{$o['program']['name']} · {$o['season']['name']}")->all())
            ->toBe(['Fútbol · 2026', 'Pádel · 2026', 'Pádel · Colonia']);

        $futbol = $options[0];
        expect($futbol['suggested_group_id'])->toBe($this->sub8->id)
            ->and(collect($futbol['groups'])->pluck('name')->all())->toBe(['Sub-10', 'Sub-8'])
            ->and($futbol['season'])->toBe(['id' => $this->season->id, 'name' => '2026', 'starts_on' => '2026-02-01', 'ends_on' => '2026-11-30']);
        $sub8 = collect($futbol['groups'])->firstWhere('id', $this->sub8->id);
        expect($sub8['capacity'])->toBe(20)->and($sub8['spots_left'])->toBe(20)->and($sub8['full'])->toBeFalse();
        expect($options[2]['suggested_group_id'])->toBeNull()
            ->and($options[2]['groups'][0]['id'])->toBe($inicial->id);
    });

    it('pide la inscripción: queda en revisión, sin alumno ni cuotas, y avisa a quienes aprueban', function () {
        $charges = Charge::query()->count();
        $response = asMember($this->tutor, 'POST', 'enrollment-requests', sofia([
            'notes' => 'Es su primer año.',
            'medical' => ['blood_type' => 'O+', 'allergies' => 'Penicilina', 'conditions' => ''],
        ]))->assertCreated()
            ->assertJsonPath('data.status', 'pendiente')
            ->assertJsonPath('data.status_label', 'En revisión')
            ->assertJsonPath('data.child.full_name', 'Sofía Benítez')
            ->assertJsonPath('data.child.birth_date', '2018-07-02')
            ->assertJsonPath('data.relationship', 'madre')
            ->assertJsonPath('data.has_medical', true)
            ->assertJsonPath('data.group.name', 'Sub-8')
            ->assertJsonPath('data.group.program.name', 'Fútbol')
            ->assertJsonPath('data.season.name', '2026')
            ->assertJsonPath('data.student_id', null);

        expect($response->json('data'))->not->toHaveKey('medical');
        $request = EnrollmentRequest::query()->sole();
        expect($request->medical)->toBe(['blood_type' => 'O+', 'allergies' => 'Penicilina'])
            ->and(Student::query()->count())->toBe(1)
            ->and(Charge::query()->count())->toBe($charges);

        Notification::assertSentTo($this->secretary, EnrollmentRequested::class,
            fn (EnrollmentRequested $n) => $n->body === 'Ana Benítez quiere inscribir a Sofía Benítez (8 años) en Sub-8 · Fútbol.');
        Notification::assertNotSentTo($this->tutor, EnrollmentRequested::class);
    });

    it('valida los datos, la temporada y la categoría', function () {
        asMember($this->tutor, 'POST', 'enrollment-requests', sofia(['first_name' => '', 'birth_date' => '2026-10-04']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name' => 'Ingresá el nombre.', 'birth_date' => 'La fecha de nacimiento tiene que ser pasada.']);

        $padel = Program::factory()->for($this->jakare)->create(['name' => 'Pádel']);
        $colonia = Season::factory()->for($this->jakare)->create(['name' => 'Colonia', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-31']);
        $colonia->programs()->attach($padel);
        asMember($this->tutor, 'POST', 'enrollment-requests', sofia(['season_id' => $colonia->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['group_id' => 'Elegí una categoría de la temporada.']);

        $past = Season::factory()->for($this->jakare)->create(['name' => '2025', 'starts_on' => '2025-02-01', 'ends_on' => '2025-11-30']);
        asMember($this->tutor, 'POST', 'enrollment-requests', sofia(['season_id' => $past->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['season_id' => 'Elegí una temporada vigente o próxima.']);
    });

    it('no repite una solicitud pendiente ni pide lo que ya tiene', function () {
        requestFor($this->tutor);

        asMember($this->tutor, 'POST', 'enrollment-requests', sofia(['document' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name' => 'Ya mandaste una solicitud para Sofía; esperá a que el club la revise.']);

        asMember($this->tutor, 'POST', 'enrollment-requests', sofia([
            'first_name' => 'Mateo', 'birth_date' => '2016-03-14', 'document' => null, 'group_id' => $this->sub10->id,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name' => 'Mateo ya tiene inscripción en Fútbol (2026).']);
    });

    it('ve sus solicitudes y cancela la pendiente', function () {
        $pending = requestFor($this->tutor);
        $other = memberOf($this->jakare);
        $foreign = requestFor($other, ['first_name' => 'Lucas', 'document' => '999']);

        asMember($this->tutor, 'GET', 'enrollment-requests')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id);

        asMember($this->tutor, 'DELETE', "enrollment-requests/{$foreign->id}")->assertNotFound();
        asMember($this->tutor, 'DELETE', "enrollment-requests/{$pending->id}")->assertNoContent();

        expect($pending->fresh()->status)->toBe(EnrollmentRequestStatus::Cancelled)
            ->and($pending->fresh()->medical)->toBeNull();
        asMember($this->tutor, 'GET', 'enrollment-requests')->assertJsonCount(0, 'data');
        asMember($this->tutor, 'DELETE', "enrollment-requests/{$pending->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'Esta solicitud ya fue revisada.']);
    });

    it('no revisa solicitudes ni tiene el permiso en la organización', function () {
        asMember($this->tutor, 'GET', 'enrollment-requests/review')->assertForbidden();
        $request = requestFor($this->tutor);
        asMember($this->tutor, 'POST', "enrollment-requests/{$request->id}/approve")->assertForbidden();

        expect(asMember($this->tutor, 'GET', 'organization')->json('data.membership.permissions'))->not->toContain('manage_enrollment_requests')
            ->and(asMember($this->secretary, 'GET', 'organization')->json('data.membership.permissions'))->toContain('manage_enrollment_requests');
    });
});

describe('quien aprueba', function () {
    it('lista las pendientes con quién la pidió, el cupo y qué se cobra del mes', function () {
        $this->tutor->update(['phone' => '+595981123456']);
        requestFor($this->tutor);

        $data = asMember($this->secretary, 'GET', 'enrollment-requests/review')->assertOk()->json('data.0');

        expect($data['requested_by'])->toBe(['name' => 'Ana Benítez', 'phone' => '0981 123 456', 'email' => $this->tutor->email])
            ->and($data['age'])->toBe(8)
            ->and($data['existing_student'])->toBeNull()
            ->and(collect($data['group_options'])->firstWhere('suggested', true)['id'])->toBe($this->sub8->id)
            ->and($data['mid_period']['label'])->toBe('Se inscribe a mitad de mes: se cobra')
            ->and($data['mid_period']['default'])->toBe('completo')
            ->and(collect($data['mid_period']['options'])->pluck('value')->all())->toBe(['completo', 'proporcional', 'proximo']);
    });

    it('aprobar da de alta al hijo con el tutor, la familia, la inscripción y la cuota del mes', function () {
        $request = requestFor($this->tutor, ['medical' => ['blood_type' => 'O+', 'allergies' => 'Penicilina']]);

        $response = asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve")->assertOk()
            ->assertJsonPath('data.status', 'aprobada');

        $sofia = Student::query()->where('first_name', 'Sofía')->sole();
        expect($response->json('data.student_id'))->toBe($sofia->id)
            ->and($sofia->document)->toBe('7123456')
            ->and($sofia->family_id)->toBe($this->family->id)
            ->and($sofia->guardians()->sole()->id)->toBe($this->guardian->id)
            ->and($this->guardian->fresh()->first_name)->toBe('Ana María')
            ->and(Guardian::query()->count())->toBe(1);

        $enrollment = $sofia->enrollments()->sole();
        expect($enrollment->group_id)->toBe($this->sub8->id)
            ->and($enrollment->status)->toBe(EnrollmentStatus::Active)
            ->and(Charge::query()->where('student_id', $sofia->id)->sole()->final_amount)->toBe(150000);

        expect(MedicalRecord::query()->where('student_id', $sofia->id)->sole()->allergies)->toBe('Penicilina')
            ->and($request->fresh()->medical)->toBeNull()
            ->and($request->fresh()->reviewed_by)->toBe($this->secretary->id);

        Notification::assertSentTo($this->tutor, EnrollmentRequestReviewed::class, fn (EnrollmentRequestReviewed $n) => $n->approved
            && $n->body === 'Aprobamos la inscripción de Sofía en Sub-8 · Fútbol (2026). Ya ves sus clases y sus cuotas en la app.'
            && $n->toPush($this->tutor)->data['route'] === "/hijos/{$sofia->id}");

        asMember($this->tutor, 'GET', 'students')->assertJsonCount(2, 'data');
    });

    it('quien elige "desde el mes que viene" no paga octubre', function () {
        $request = requestFor($this->tutor);

        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve", ['mid_period' => 'proximo'])->assertOk();

        $sofia = Student::query()->where('first_name', 'Sofía')->sole();
        expect(Charge::query()->where('student_id', $sofia->id)->count())->toBe(0);
    });

    it('un miembro sin ficha de tutor queda como tutor de su hijo', function () {
        $coach = memberOf($this->jakare, ['name' => 'Carlos Gómez Vera', 'phone' => '+595981555444', 'phone_verified_at' => now()]);
        $request = requestFor($coach, ['relationship' => 'padre', 'document' => '8000111']);

        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve")->assertOk();

        $guardian = Guardian::query()->where('user_id', $coach->id)->sole();
        expect($guardian->first_name)->toBe('Carlos')
            ->and($guardian->last_name)->toBe('Gómez Vera')
            ->and($guardian->phone)->toBe('+595981555444')
            ->and($guardian->students()->sole()->pivot->relationship)->toBe('padre')
            ->and($coach->fresh()->hasCurrentRole($this->jakare, OrganizationRole::Guardian))->toBeTrue();

        asMember($coach, 'GET', 'students')->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Sofía');
    });

    it('si el chico ya está cargado lo reutiliza y le suma el tutor', function () {
        $sofia = Student::factory()->for($this->jakare)->create(['first_name' => 'Sofía', 'last_name' => 'Benítez', 'document' => '7123456', 'birth_date' => '2018-07-02']);
        $father = Guardian::factory()->for($this->jakare)->create(['first_name' => 'Carlos', 'last_name' => 'Benítez']);
        $father->students()->attach($sofia->id, ['relationship' => 'padre']);
        $request = requestFor($this->tutor);

        asMember($this->secretary, 'GET', 'enrollment-requests/review')
            ->assertJsonPath('data.0.existing_student.id', $sofia->id)
            ->assertJsonPath('data.0.existing_student.guardians', ['Carlos Benítez']);

        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve")->assertOk()
            ->assertJsonPath('data.student_id', $sofia->id);

        expect(Student::query()->count())->toBe(2)
            ->and($sofia->guardians()->pluck('guardians.id')->sort()->values()->all())->toBe(collect([$father->id, $this->guardian->id])->sort()->values()->all());
    });

    it('puede cambiar la categoría (de la misma disciplina) y pide confirmar el cupo lleno', function () {
        $this->sub8->update(['capacity' => 1]);
        Enrollment::factory()->create(['group_id' => $this->sub8->id, 'season_id' => $this->season->id, 'status' => EnrollmentStatus::Active,
            'student_id' => Student::factory()->for($this->jakare)->create()->id]);
        $request = requestFor($this->tutor);
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

    it('aprobar a otra categoría y dos veces no duplica nada', function () {
        $request = requestFor($this->tutor);

        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve", ['group_id' => $this->sub10->id])->assertOk()
            ->assertJsonPath('data.group.name', 'Sub-10');
        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'Esta solicitud ya fue revisada.']);

        expect(Student::query()->where('first_name', 'Sofía')->sole()->enrollments()->sole()->group_id)->toBe($this->sub10->id);
    });

    it('rechazar pide el motivo y avisa al tutor', function () {
        $request = requestFor($this->tutor, ['medical' => ['allergies' => 'Penicilina']]);

        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'Contale a la familia por qué no la aprobás.']);
        asMember($this->secretary, 'POST', "enrollment-requests/{$request->id}/reject", ['reason' => 'No hay lugar en Sub-8 este año.'])->assertOk()
            ->assertJsonPath('data.status', 'rechazada')
            ->assertJsonPath('data.status_label', 'No aprobada')
            ->assertJsonPath('data.rejection_reason', 'No hay lugar en Sub-8 este año.');

        expect($request->fresh()->medical)->toBeNull()
            ->and(Student::query()->count())->toBe(1);
        Notification::assertSentTo($this->tutor, EnrollmentRequestReviewed::class, fn (EnrollmentRequestReviewed $n) => ! $n->approved
            && $n->body === 'El club no aprobó la inscripción de Sofía: No hay lugar en Sub-8 este año.');

        // El tutor la sigue viendo, con el motivo.
        asMember($this->tutor, 'GET', 'enrollment-requests')->assertJsonPath('data.0.rejection_reason', 'No hay lugar en Sub-8 este año.');
    });

    it('con el permiso "Gestionar solicitudes de inscripción" también aprueba', function () {
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

    it('no ve ni aprueba las de otra organización', function () {
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

describe('panel', function () {
    beforeEach(function () {
        $this->actingAs($this->secretary);
        filament()->setTenant($this->jakare);
    });

    it('lista las solicitudes, aprueba y rechaza', function () {
        $first = requestFor($this->tutor);
        $second = requestFor($this->tutor, ['first_name' => 'Lucía', 'document' => '7999888']);
        $this->actingAs($this->secretary);

        $this->get('/admin/jakare/solicitudes-de-inscripcion')->assertOk()->assertSee('Sofía Benítez');

        Livewire::test(ManageEnrollmentRequests::class)
            ->assertCanSeeTableRecords([$first, $second])
            ->callTableAction('approve', $first, data: ['group_id' => $this->sub8->id, 'mid_period' => 'completo'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('reject', $second, data: ['reason' => 'Falta el documento.'])
            ->assertHasNoTableActionErrors();

        expect($first->fresh()->status)->toBe(EnrollmentRequestStatus::Approved)
            ->and($first->fresh()->student_id)->not->toBeNull()
            ->and($second->fresh()->status)->toBe(EnrollmentRequestStatus::Rejected);
    });

    it('un tutor no entra a las solicitudes', function () {
        $this->actingAs($this->tutor);

        $this->get('/admin/jakare/solicitudes-de-inscripcion')->assertForbidden();
    });
});
