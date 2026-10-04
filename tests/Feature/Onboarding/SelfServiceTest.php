<?php

use App\Actions\Auth\SendVerificationCode;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Mail\EmailVerificationCodeMail;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Roles\RoleAssigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Mail::fake();
});

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Laura Gómez',
        'email' => 'Laura@Test.com',
        'password' => 'secreta123',
        'password_confirmation' => 'secreta123',
        'device_name' => 'app',
        'terms' => true,
    ], $overrides);
}

/** El código que se mandó por email al usuario. */
function sentCode(string $email): string
{
    $code = null;
    Mail::assertQueued(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $mail) use ($email, &$code) {
        $code = $mail->code;

        return $mail->hasTo($email);
    });

    return $code;
}

describe('cuenta', function () {
    it('crea la cuenta sin organizaciones, sin verificar, y manda el código', function () {
        $response = $this->postJson('/api/v1/auth/register', registerPayload())->assertCreated();

        $user = User::query()->where('email', 'laura@test.com')->firstOrFail();
        expect($response->json('token'))->not->toBeEmpty()
            ->and($user->hasVerifiedEmail())->toBeFalse()
            ->and($user->terms_accepted_at)->not->toBeNull()
            ->and($user->terms_version)->toBe('2026-10')
            ->and(sentCode('laura@test.com'))->toMatch('/^\d{6}$/');

        $this->withToken($response->json('token'))->getJson('/api/v1/me')
            ->assertJsonPath('data.verified', false)
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.organizations', []);
    });

    it('pide aceptar los términos y no repite correos', function () {
        User::factory()->create(['email' => 'laura@test.com']);

        $this->postJson('/api/v1/auth/register', registerPayload(['terms' => false]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.terms.0', 'Tenés que aceptar los términos.')
            ->assertJsonPath('errors.email.0', 'Ya hay una cuenta con ese correo. Ingresá con tu contraseña.');
    });

    it('verifica el correo con el código', function () {
        $this->postJson('/api/v1/auth/register', registerPayload());
        $user = User::query()->where('email', 'laura@test.com')->firstOrFail();

        $code = sentCode('laura@test.com');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/verify', ['code' => $code === '111111' ? '222222' : '111111'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'El código no es correcto.');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/verify', ['code' => $code])
            ->assertNoContent();

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
            ->and(DB::table('verification_codes')->count())->toBe(0);
    });

    it('el código vence y tiene intentos limitados', function () {
        $user = User::factory()->unverified()->create();
        app(SendVerificationCode::class)->handle($user);
        $code = sentCode($user->email);
        $wrong = $code === '123456' ? '654321' : '123456';

        foreach (range(1, 5) as $attempt) {
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/verify', ['code' => $wrong])->assertUnprocessable();
        }
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/verify', ['code' => $code])
            ->assertJsonPath('errors.code.0', 'Demasiados intentos. Pedí un código nuevo.');

        // Uno nuevo (pasado el minuto) vuelve a habilitar los intentos, pero vence a los 15 minutos.
        $this->travel(61)->seconds();
        Mail::fake();
        app(SendVerificationCode::class)->handle($user);
        $this->travel(16)->minutes();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/verify', ['code' => sentCode($user->email)])
            ->assertJsonPath('errors.code.0', 'El código venció. Pedí uno nuevo.');
    });

    it('reenviar manda un código nuevo, una vez por minuto', function () {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/verify/resend')->assertNoContent();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/verify/resend')->assertTooManyRequests();

        Mail::assertQueued(EmailVerificationCodeMail::class, 1);
    });

    it('el email del código se renderiza', function () {
        $html = (new EmailVerificationCodeMail('Laura Gómez', '042137'))->render();
        $reset = (new EmailVerificationCodeMail('Laura Gómez', '042137', 'reset'))->render();

        expect($html)->toContain('Hola, Laura')->toContain('042137')->toContain('confirmar tu cuenta')
            ->and($reset)->toContain('contraseña nueva');
    });
});

describe('alta del club', function () {
    beforeEach(function () {
        $this->user = User::factory()->create(['name' => 'Laura Gómez']);
    });

    it('sugiere un identificador libre', function () {
        Organization::factory()->create(['slug' => 'club-jakare']);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/organizations/slug?value=Club%20Jakare')
            ->assertExactJson(['slug' => 'club-jakare', 'available' => false, 'suggestion' => 'club-jakare-2']);
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/organizations/slug?value=Academia%20Ritmo')
            ->assertExactJson(['slug' => 'academia-ritmo', 'available' => true, 'suggestion' => null]);
        // Palabras reservadas.
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/organizations/slug?value=admin')
            ->assertJsonPath('available', false);
    });

    it('crea el club y deja al usuario como administrador', function () {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/organizations', [
            'name' => 'Academia Ritmo',
            'type' => 'academy',
            'slug' => 'academia-ritmo',
            'terminology' => ['student' => 'Alumna'],
        ])->assertCreated()->assertJsonPath('data.slug', 'academia-ritmo');

        $organization = Organization::query()->where('slug', 'academia-ritmo')->firstOrFail();
        expect($organization->self_service)->toBeTrue()
            ->and($organization->term('student'))->toBe('Alumna')
            ->and($organization->term('instructor'))->toBe('Profesor')
            ->and($organization->currency)->toBe('PYG')
            ->and($organization->features)->toBeNull()
            ->and($this->user->isOrganizationAdmin($organization))->toBeTrue()
            ->and(Role::query()->where('organization_id', $organization->id)->count())->toBe(count(OrganizationRole::cases()))
            ->and(Activity::query()->where('description', 'Organización creada (autoservicio)')->exists())->toBeTrue();

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/me')
            ->assertJsonPath('data.organizations.0.slug', 'academia-ritmo');
        // El admin tiene todos los permisos, también el de configurar.
        expect($this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/organization', ['X-Organization' => 'academia-ritmo'])
            ->json('data.membership.permissions'))->toContain('configure_organization');
    });

    it('crea el club con el vocabulario de las plantillas, para cada tipo', function () {
        $types = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/onboarding/templates')
            ->assertOk()
            ->json('data.organization_types');

        expect($types)->toHaveCount(count(OrganizationType::cases()));

        foreach ($types as $type) {
            $slug = 'prueba-'.str_replace('_', '-', $type['value']);

            // Lo mismo que manda la app: la terminología tal cual llega, con todas sus claves.
            $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/organizations', [
                'name' => "Prueba {$type['label']}",
                'type' => $type['value'],
                'slug' => $slug,
                'terminology' => $type['terminology'],
            ])->assertCreated();

            $organization = Organization::query()->where('slug', $slug)->firstOrFail();
            foreach ($type['terminology'] as $key => $term) {
                expect($organization->term($key))->toBe($term);
            }
        }
    });

    it('rechaza claves de vocabulario desconocidas', function () {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/organizations', [
            'name' => 'Academia Ritmo', 'type' => 'academy', 'slug' => 'academia-ritmo',
            'terminology' => ['space' => 'Pileta', 'coach' => 'Entrenador'],
        ])->assertUnprocessable()->assertJsonValidationErrors('terminology');
    });

    it('sin verificar la cuenta no se crea', function () {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/organizations', [
            'name' => 'Academia Ritmo', 'type' => 'academy', 'slug' => 'academia-ritmo',
        ])->assertForbidden()->assertJsonPath('message', 'Verificá tu cuenta para crear un club.');
    });

    it('valida el identificador', function (string $slug, string $message) {
        Organization::factory()->create(['slug' => 'jakare']);

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/organizations', [
            'name' => 'Club', 'type' => 'club', 'slug' => $slug,
        ])->assertUnprocessable()->assertJsonPath('errors.slug.0', $message);
    })->with([
        ['jakare', 'Ese identificador ya está en uso.'],
        ['admin', 'Ese identificador no se puede usar.'],
        ['Club Jakare', 'Solo letras minúsculas, números y guiones.'],
    ]);
});

it('el permiso configure_organization es solo del administrador', function () {
    $organization = Organization::factory()->create(['slug' => 'jakare']);
    $tutor = memberOf($organization);
    app(RoleAssigner::class)->assign($organization, $tutor, OrganizationRole::Guardian);

    $this->actingAs($tutor, 'sanctum')->getJson('/api/v1/organization', ['X-Organization' => 'jakare'])
        ->assertJsonPath('data.membership.permissions', []);
    $this->actingAs($tutor, 'sanctum')->getJson('/api/v1/onboarding', ['X-Organization' => 'jakare'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Solo los administradores configuran el club.');
});
