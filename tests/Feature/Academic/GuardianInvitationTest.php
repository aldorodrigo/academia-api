<?php

use App\Actions\Invitations\CreateInvitation;
use App\Models\Guardian;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Mail::fake();
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    $this->guardian = Guardian::factory()->for($this->jakare)->create(['email' => 'Ana@Test.com']);
    $this->student = Student::factory()->for($this->jakare)->create(['first_name' => 'Mateo']);
    $this->student->guardians()->attach($this->guardian, ['relationship' => 'madre']);
});

it('al aceptar la invitación del tutor ve a sus hijos directamente', function () {
    [$invitation, $token] = app(CreateInvitation::class)->forGuardian($this->guardian);

    expect($invitation->email)->toBe('ana@test.com')
        ->and($invitation->guardian_id)->toBe($this->guardian->id)
        ->and($invitation->roles[0]['role'])->toBe('tutor');

    $apiToken = $this->postJson("/api/v1/invitations/{$token}/accept", [
        'name' => 'Ana', 'password' => 'secreta123', 'password_confirmation' => 'secreta123', 'device_name' => 'app',
    ])->assertCreated()->json('token');

    $user = User::query()->where('email', 'ana@test.com')->sole();
    expect($this->guardian->fresh()->user_id)->toBe($user->id);

    $this->withToken($apiToken)
        ->getJson('/api/v1/students', ['X-Organization' => 'jakare'])
        ->assertOk()
        ->assertJsonPath('data.0.first_name', 'Mateo');

    $this->withToken($apiToken)
        ->getJson('/api/v1/organization', ['X-Organization' => 'jakare'])
        ->assertJsonPath('data.membership.roles.0.name', 'tutor');
});

it('reenviar conserva el vínculo con el tutor', function () {
    [$invitation] = app(CreateInvitation::class)->forGuardian($this->guardian);

    app(CreateInvitation::class)->resend($invitation);

    expect($this->guardian->invitations()->whereNull('revoked_at')->sole()->guardian_id)->toBe($this->guardian->id);
});

it('no se puede invitar a un tutor sin celular ni correo', function () {
    $this->guardian->update(['email' => null, 'phone' => null]);

    app(CreateInvitation::class)->forGuardian($this->guardian);
})->throws(ValidationException::class);

it('un tutor con solo celular se invita para mandarle el link por WhatsApp', function () {
    Mail::fake();
    $this->guardian->update(['email' => null, 'phone' => '0981 555 444']);

    [$invitation, $token] = app(CreateInvitation::class)->forGuardian($this->guardian);

    expect($invitation->phone)->toBe('+595981555444')
        ->and($invitation->email)->toBeNull()
        ->and($invitation->whatsappUrl($token))->toStartWith('https://wa.me/595981555444?text=');
    Mail::assertNothingQueued();

    // Al aceptarla, la cuenta queda con ese celular verificado y vinculada al tutor.
    $this->getJson("/api/v1/invitations/{$token}")
        ->assertJsonPath('data.phone', '+595981555444')
        ->assertJsonPath('data.email', null)
        ->assertJsonPath('data.user_exists', false);
    $this->postJson("/api/v1/invitations/{$token}/accept", [
        'name' => 'Ana', 'password' => 'secreta123', 'password_confirmation' => 'secreta123', 'device_name' => 'app',
    ])->assertCreated();

    $user = User::query()->where('phone', '+595981555444')->sole();
    expect($user->phone_verified_at)->not->toBeNull()
        ->and($user->email)->toBeNull()
        ->and($this->guardian->fresh()->user_id)->toBe($user->id);
});

it('no pisa un tutor ya vinculado a otra cuenta', function () {
    $other = User::factory()->create();
    [, $token] = app(CreateInvitation::class)->forGuardian($this->guardian);
    $this->guardian->update(['user_id' => $other->id]);

    $this->postJson("/api/v1/invitations/{$token}/accept", [
        'name' => 'Ana', 'password' => 'secreta123', 'password_confirmation' => 'secreta123', 'device_name' => 'app',
    ])->assertCreated();

    expect($this->guardian->fresh()->user_id)->toBe($other->id);
});
