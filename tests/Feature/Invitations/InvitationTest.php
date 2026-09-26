<?php

use App\Actions\Invitations\CreateInvitation;
use App\Enums\InvitationStatus;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Mail::fake();
    $this->jakare = Organization::factory()->create(['name' => 'Club Jakare', 'slug' => 'jakare']);
    $this->ajena = Organization::factory()->create(['slug' => 'ajena']);
    $this->admin = memberOf($this->jakare);
});

function invite(Organization $organization, string $email = 'ana@test.com', ?array $roles = null): array
{
    return app(CreateInvitation::class)->handle($organization, $email, $roles ?? [
        ['role' => 'tutor'],
        ['role' => 'tesorero', 'starts_on' => '2026-01-01', 'ends_on' => '2099-12-31'],
    ]);
}

function acceptPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ana Pérez',
        'password' => 'secreta123',
        'password_confirmation' => 'secreta123',
        'device_name' => 'app',
    ], $overrides);
}

it('crear una invitación guarda el token hasheado y envía el email', function () {
    [$invitation, $token] = invite($this->jakare);

    expect($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->status())->toBe(InvitationStatus::Pending)
        ->and(Invitation::urlFor($token))->toEndWith('/invitacion/'.$token);

    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('ana@test.com'));
});

it('el email de invitación se renderiza con el link y el QR', function () {
    [$invitation, $token] = invite($this->jakare);

    $html = (new InvitationMail($invitation, $token))->render();

    expect($html)->toContain('Club Jakare')
        ->toContain('Tutor, Tesorero')
        ->toContain('/invitacion/'.$token);
});

it('una invitación nueva revoca la pendiente anterior de la misma persona', function () {
    [$vieja] = invite($this->jakare);
    invite($this->jakare);

    expect($vieja->fresh()->status())->toBe(InvitationStatus::Revoked);
});

it('un cargo de comisión sin fin de mandato no se puede invitar', function () {
    invite($this->jakare, roles: [['role' => 'presidente']]);
})->throws(ValidationException::class);

it('muestra una invitación pendiente', function () {
    [, $token] = invite($this->jakare);

    $this->getJson("/api/v1/invitations/{$token}")
        ->assertOk()
        ->assertJsonPath('data.organization', ['slug' => 'jakare', 'name' => 'Club Jakare'])
        ->assertJsonPath('data.email', 'ana@test.com')
        ->assertJsonPath('data.roles.0', ['name' => 'tutor', 'label' => 'Tutor'])
        ->assertJsonPath('data.roles.1.label', 'Tesorero')
        ->assertJsonPath('data.user_exists', false);
});

it('responde 404 si la invitación no existe, venció, se revocó o ya se usó', function (string $state) {
    [$invitation, $token] = invite($this->jakare);

    match ($state) {
        'inexistente' => $token = 'no-existe',
        'vencida' => $invitation->update(['expires_at' => now()->subMinute()]),
        'revocada' => $invitation->update(['revoked_at' => now()]),
        'usada' => $invitation->update(['accepted_at' => now()]),
    };

    $this->getJson("/api/v1/invitations/{$token}")
        ->assertNotFound()
        ->assertJsonPath('message', 'La invitación no es válida o ya venció.');

    $this->postJson("/api/v1/invitations/{$token}/accept", acceptPayload())->assertNotFound();
})->with(['inexistente', 'vencida', 'revocada', 'usada']);

it('aceptar con cuenta nueva crea el usuario, la membresía y los roles', function () {
    [$invitation, $token] = invite($this->jakare);

    $response = $this->postJson("/api/v1/invitations/{$token}/accept", acceptPayload())
        ->assertCreated()
        ->assertJsonPath('organization', 'jakare');

    $user = User::query()->where('email', 'ana@test.com')->firstOrFail();

    expect($user->name)->toBe('Ana Pérez')
        ->and($user->belongsToOrganization($this->jakare))->toBeTrue()
        ->and($user->currentRoleAssignments($this->jakare)->map->description()->all())
        ->toBe(['Tutor', 'Tesorero · hasta 31/12/2099'])
        ->and($invitation->fresh()->status())->toBe(InvitationStatus::Accepted);

    // El token sirve para la app.
    $this->withToken($response->json('token'))
        ->getJson('/api/v1/organization', ['X-Organization' => 'jakare'])
        ->assertOk()
        ->assertJsonCount(2, 'data.membership.roles');
});

it('valida nombre y contraseña para una cuenta nueva', function () {
    [, $token] = invite($this->jakare);

    $this->postJson("/api/v1/invitations/{$token}/accept", acceptPayload([
        'name' => '',
        'password' => 'corta',
        'password_confirmation' => 'otra',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['name', 'password']);
});

it('con cuenta existente pide su contraseña y no crea otro usuario', function () {
    $ana = memberOf($this->ajena, ['email' => 'ana@test.com', 'password' => 'mi-clave-1']);
    [, $token] = invite($this->jakare, roles: [['role' => 'tutor']]);

    $this->getJson("/api/v1/invitations/{$token}")->assertJsonPath('data.user_exists', true);

    $this->postJson("/api/v1/invitations/{$token}/accept", ['password' => 'incorrecta', 'device_name' => 'app'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->postJson("/api/v1/invitations/{$token}/accept", ['password' => 'mi-clave-1', 'device_name' => 'app'])
        ->assertCreated();

    expect(User::query()->where('email', 'ana@test.com')->count())->toBe(1)
        ->and($ana->belongsToOrganization($this->jakare))->toBeTrue()
        ->and($ana->belongsToOrganization($this->ajena))->toBeTrue();
});

it('no se puede aceptar dos veces', function () {
    [, $token] = invite($this->jakare);

    $this->postJson("/api/v1/invitations/{$token}/accept", acceptPayload())->assertCreated();
    $this->postJson("/api/v1/invitations/{$token}/accept", acceptPayload())->assertNotFound();

    expect(RoleAssignment::withoutGlobalScopes()->count())->toBe(2);
});

it('una invitación de una organización no da acceso a otra', function () {
    [, $token] = invite($this->jakare);

    $token = $this->postJson("/api/v1/invitations/{$token}/accept", acceptPayload())->json('token');

    $this->withToken($token)
        ->getJson('/api/v1/organization', ['X-Organization' => 'ajena'])
        ->assertForbidden();
});
