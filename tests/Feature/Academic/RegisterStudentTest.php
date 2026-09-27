<?php

use App\Actions\Students\RegisterStudent;
use App\Enums\EnrollmentStatus;
use App\Enums\GroupCriterion;
use App\Exceptions\ImportRowException;
use App\Mail\InvitationMail;
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
    $this->season = Season::factory()->for($this->jakare)->create([
        'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
    ]);
    $this->program = Program::factory()->for($this->jakare)->create(['name' => 'Fútbol']);
    $this->sub10 = Group::factory()->for($this->program)->create(['name' => 'Sub-10', 'organization_id' => $this->jakare->id, 'min_age' => 9, 'max_age' => 10]);
});

function register(array $student, array $guardians, ?EnrollmentStatus $status = EnrollmentStatus::Active): Student
{
    return app(RegisterStudent::class)->handle(
        test()->jakare,
        [...['first_name' => 'Mateo', 'last_name' => 'Benítez', 'birth_date' => '2016-03-14'], ...$student],
        test()->sub10,
        test()->season,
        $status,
        $guardians,
    );
}

it('inscribe al jugador en la categoría y temporada elegidas', function () {
    $student = register(['document' => '6123456'], [['first_name' => 'Ana', 'last_name' => 'Benítez', 'relationship' => 'madre']], EnrollmentStatus::Scholarship);

    $enrollment = $student->enrollments()->withoutGlobalScopes()->sole();
    expect($enrollment->group_id)->toBe($this->sub10->id)
        ->and($enrollment->season_id)->toBe($this->season->id)
        ->and($enrollment->status)->toBe(EnrollmentStatus::Scholarship);
});

it('reutiliza el tutor por correo y los hermanos comparten familia', function () {
    $mateo = register([], [['first_name' => 'Ana', 'last_name' => 'Benítez', 'email' => 'ana@test.com']]);
    $sofia = register(['first_name' => 'Sofía', 'birth_date' => '2017-07-02'], [['first_name' => 'Ana', 'last_name' => 'B.', 'email' => 'ANA@test.com']]);

    app(CurrentOrganization::class)->set($this->jakare);
    expect(Guardian::query()->count())->toBe(1)
        ->and($sofia->fresh()->family_id)->toBe($mateo->fresh()->family_id)
        ->and(Guardian::query()->sole()->family_id)->toBe($mateo->fresh()->family_id);
});

it('invita solo a quien lo pidió, tiene correo y no usa la app', function () {
    $action = app(RegisterStudent::class);
    $withAccount = Guardian::factory()->for($this->jakare)->create(['email' => 'luis@test.com', 'user_id' => memberOf($this->jakare)->id]);

    $action->handle($this->jakare, ['first_name' => 'Mateo', 'last_name' => 'Benítez', 'birth_date' => '2016-03-14'], $this->sub10, $this->season, EnrollmentStatus::Active, [
        ['first_name' => 'Ana', 'email' => 'ana@test.com', 'invite' => true],
        ['first_name' => 'Luis', 'email' => $withAccount->email, 'invite' => true],
        ['first_name' => 'Rosa', 'email' => 'rosa@test.com', 'invite' => false],
        ['first_name' => 'Pedro', 'invite' => true],
    ]);

    expect($action->invited)->toBe(1);
    Mail::assertQueuedCount(1);
    Mail::assertQueued(InvitationMail::class, fn ($mail) => $mail->hasTo('ana@test.com'));
});

it('un menor necesita tutor; un adulto no', function () {
    expect(fn () => register([], []))->toThrow(ImportRowException::class, 'El jugador es menor de edad');

    $adult = register(['first_name' => 'Laura', 'birth_date' => '1990-01-01'], []);
    expect($adult->exists)->toBeTrue();
});

it('el tutor que ya usa la app ve al hijo nuevo sin otra invitación', function () {
    $user = memberOf($this->jakare);
    Guardian::factory()->for($this->jakare)->create(['email' => 'ana@test.com', 'user_id' => $user->id]);

    register([], [['first_name' => 'Ana', 'email' => 'ana@test.com', 'invite' => true]]);

    Mail::assertNothingQueued();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/students', ['X-Organization' => 'jakare'])
        ->assertJsonPath('data.0.first_name', 'Mateo');
});

it('sugiere la categoría por año de nacimiento solo si hay una', function () {
    app(CurrentOrganization::class)->set($this->jakare);
    Group::factory()->for($this->program)->create(['name' => 'Sub-12', 'organization_id' => $this->jakare->id, 'min_age' => 11, 'max_age' => 12]);

    expect(Group::suggestFor(now()->setDate(2016, 3, 14), $this->season)?->id)->toBe($this->sub10->id)
        ->and(Group::suggestFor(now()->setDate(2005, 1, 1), $this->season))->toBeNull();

    // Otra disciplina por edad con una categoría que también corresponde: no se adivina.
    $otro = Program::factory()->for($this->jakare)->create(['name' => 'Básquet', 'group_criterion' => GroupCriterion::BirthYear]);
    Group::factory()->for($otro)->create(['name' => 'U10', 'organization_id' => $this->jakare->id, 'min_age' => 9, 'max_age' => 10]);

    expect(Group::suggestFor(now()->setDate(2016, 3, 14), $this->season))->toBeNull()
        ->and(Group::suggestFor(now()->setDate(2016, 3, 14), $this->season, 'Fútbol')?->id)->toBe($this->sub10->id);
});
