<?php

use App\Actions\Invitations\CreateInvitation;
use App\Mail\ConfirmEmailMail;
use App\Mail\InvitationMail;
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
        'name' => 'Ana', 'password' => 'secreta123', 'password_confirmation' => 'secreta123', 'device_name' => 'app', 'terms' => true,
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

it('invitar de nuevo a un tutor reutiliza su invitación pendiente', function () {
    [$invitation, $token] = app(CreateInvitation::class)->forGuardian($this->guardian);
    [$again, $newToken] = app(CreateInvitation::class)->forGuardian($this->guardian);

    expect($again->id)->toBe($invitation->id)
        ->and($this->guardian->invitations()->sole()->id)->toBe($invitation->id)
        ->and($newToken)->not->toBe($token);
    $this->getJson("/api/v1/invitations/{$token}")->assertNotFound();
    $this->getJson("/api/v1/invitations/{$newToken}")->assertOk()->assertJsonPath('data.name', $this->guardian->full_name);
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
        'name' => 'Ana', 'password' => 'secreta123', 'password_confirmation' => 'secreta123', 'device_name' => 'app', 'terms' => true,
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
        'name' => 'Ana', 'password' => 'secreta123', 'password_confirmation' => 'secreta123', 'device_name' => 'app', 'terms' => true,
    ])->assertCreated();

    expect($this->guardian->fresh()->user_id)->toBe($other->id);
});

it('un tutor con celular y correo recibe la invitación por los dos lados', function () {
    $this->guardian->update(['phone' => '0981 555 444']);

    [$invitation, $token] = app(CreateInvitation::class)->forGuardian($this->guardian);

    expect($invitation->phone)->toBe('+595981555444')
        ->and($invitation->email)->toBe('ana@test.com')
        ->and(urldecode((string) $invitation->whatsappUrl($token)))->toStartWith('https://wa.me/595981555444?text=Hola');
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('ana@test.com'));

    // Al aceptarla, el celular queda verificado y al correo le llega el link para confirmarlo.
    $this->postJson("/api/v1/invitations/{$token}/accept", [
        'name' => 'Ana', 'password' => 'secreta123', 'password_confirmation' => 'secreta123', 'device_name' => 'app', 'terms' => true,
    ])->assertCreated();

    $user = User::query()->where('phone', '+595981555444')->sole();
    expect($user->email)->toBe('ana@test.com')
        ->and($user->phone_verified_at)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and($this->guardian->fresh()->user_id)->toBe($user->id);
    Mail::assertQueued(ConfirmEmailMail::class, fn (ConfirmEmailMail $mail) => $mail->hasTo('ana@test.com'));
});

it('la cuenta existente se busca por el celular o el correo verificados', function () {
    $this->guardian->update(['phone' => '0981 555 444']);
    // Otra cuenta con ese correo sin verificar: no cuenta y se lo saca al crear la cuenta nueva.
    $other = User::factory()->unverified()->create(['phone' => '+595981999888', 'phone_verified_at' => now(), 'email' => 'ana@test.com']);
    [$invitation, $token] = app(CreateInvitation::class)->forGuardian($this->guardian);

    expect($invitation->existingUser())->toBeNull();

    $this->postJson("/api/v1/invitations/{$token}/accept", [
        'name' => 'Ana', 'password' => 'secreta123', 'password_confirmation' => 'secreta123', 'device_name' => 'app', 'terms' => true,
    ])->assertCreated();

    expect($other->fresh()->email)->toBeNull()
        ->and(User::query()->where('email', 'ana@test.com')->sole()->phone)->toBe('+595981555444');

    // Con el correo verificado, la invitación es para esa cuenta.
    $verified = User::factory()->create(['email' => 'pedro@test.com']);
    $this->guardian->update(['email' => 'pedro@test.com', 'phone' => '0981 777 666', 'user_id' => null]);
    [$second] = app(CreateInvitation::class)->forGuardian($this->guardian);
    expect($second->existingUser()?->id)->toBe($verified->id);
});
