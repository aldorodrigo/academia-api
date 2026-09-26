<?php

use App\Actions\Students\ImportStudentRow;
use App\Enums\EnrollmentStatus;
use App\Exceptions\ImportRowException;
use App\Mail\InvitationMail;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Season;
use App\Models\Student;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    $this->season = Season::factory()->for($this->jakare)->create(['name' => '2026', 'is_current' => true]);
    $program = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $this->group = Group::factory()->for($program)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id]);
});

function importRow(Organization $organization, array $overrides = [], bool $invite = false): Student
{
    return app(ImportStudentRow::class)->handle($organization, array_merge([
        'first_name' => 'Mateo',
        'last_name' => 'Benítez',
        'document' => '6123456',
        'birth_date' => '14/03/2016',
        'shirt_size' => '12',
        'position' => null,
        'program' => 'fútbol',
        'group' => 'Sub-10',
        'status' => null,
        'guardians' => [
            ['first_name' => 'Ana', 'last_name' => 'Benítez', 'email' => 'Ana@Test.com', 'phone' => '0981 111 222', 'relationship' => 'Madre'],
            ['first_name' => 'Luis', 'last_name' => 'Benítez', 'email' => null, 'phone' => null, 'relationship' => 'padre'],
        ],
    ], $overrides), $invite);
}

it('crea alumno, tutores, familia e inscripción activa en la temporada actual', function () {
    $student = importRow($this->jakare);

    app(CurrentOrganization::class)->set($this->jakare);
    $student->refresh();

    expect($student->birth_date->toDateString())->toBe('2016-03-14')
        ->and($student->organization_id)->toBe($this->jakare->id)
        ->and($student->family->name)->toBe('Familia Benítez')
        ->and($student->guardians)->toHaveCount(2)
        ->and($student->guardians->firstWhere('first_name', 'Ana')->email)->toBe('ana@test.com')
        ->and($student->guardians->firstWhere('first_name', 'Ana')->pivot->relationship)->toBe('madre')
        ->and($student->guardians->pluck('family_id')->unique()->all())->toBe([$student->family_id]);

    $enrollment = Enrollment::query()->sole();
    expect($enrollment->status)->toBe(EnrollmentStatus::Active)
        ->and($enrollment->season_id)->toBe($this->season->id)
        ->and($enrollment->group_id)->toBe($this->group->id);
});

it('reimportar no duplica y los hermanos comparten familia y tutores', function () {
    importRow($this->jakare);
    importRow($this->jakare, ['status' => 'Becado']);
    $sister = importRow($this->jakare, ['first_name' => 'Sofía', 'document' => '7000000', 'birth_date' => '2018-05-02']);

    app(CurrentOrganization::class)->set($this->jakare);

    expect(Student::query()->count())->toBe(2)
        ->and(Guardian::query()->count())->toBe(2)
        ->and(Enrollment::query()->where('student_id', '!=', $sister->id)->sole()->status)->toBe(EnrollmentStatus::Scholarship)
        ->and($sister->fresh()->family_id)->toBe(Student::query()->where('first_name', 'Mateo')->value('family_id'));
});

it('informa errores de la fila', function (array $overrides, string $message) {
    expect(fn () => importRow($this->jakare, $overrides))->toThrow(ImportRowException::class, $message);
})->with([
    'grupo inexistente' => [['group' => 'Sub-99'], 'No existe Categoría "Sub-99" en Fútbol.'],
    'programa inexistente' => [['program' => 'Pádel'], 'No existe Disciplina "Pádel".'],
    'fecha inválida' => [['birth_date' => '31/02/2016x'], 'Fecha de nacimiento inválida'],
    'estado inválido' => [['status' => 'libre'], 'Estado "libre" inválido'],
    'email inválido' => [['guardians' => [['first_name' => 'Ana', 'email' => 'no-es-email']]], 'Email de tutor inválido'],
]);

it('sin temporada actual no importa', function () {
    $this->season->update(['is_current' => false]);

    importRow($this->jakare);
})->throws(ImportRowException::class, 'No hay una temporada actual.');

it('opcionalmente invita a los tutores con email, una sola vez', function () {
    importRow($this->jakare, invite: true);
    importRow($this->jakare, invite: true);

    Mail::assertQueuedCount(1);
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('ana@test.com'));
});

it('no usa grupos de otra organización', function () {
    $ajena = Organization::factory()->create();
    Season::factory()->for($ajena)->create(['is_current' => true]);

    importRow($ajena);
})->throws(ImportRowException::class, 'No existe Disciplina "fútbol".');
