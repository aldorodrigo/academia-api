<?php

use App\Actions\Auth\SendVerificationCode;
use App\Jobs\SendWhatsAppCode;
use App\Mail\ConfirmEmailMail;
use App\Mail\EmailVerificationCodeMail;
use App\Mail\WhatsAppSimulatedMail;
use App\Models\Guardian;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\WhatsAppPaused;
use App\Support\Phone;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Verification\CodeGuard;
use App\Support\Verification\EmailConfirmation;
use App\Support\Verification\TooManyCodes;
use App\Support\WhatsApp\CloudApiWhatsAppSender;
use App\Support\WhatsApp\LogWhatsAppSender;
use App\Support\WhatsApp\MailWhatsAppSender;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Mail::fake();
    Bus::fake([SendWhatsAppCode::class]);
});

function phonePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Laura Gómez',
        'phone' => '0981 123 456',
        'password' => 'secreta123',
        'password_confirmation' => 'secreta123',
        'device_name' => 'app',
        'terms' => true,
    ], $overrides);
}

/** El último código que se mandó por WhatsApp a ese celular. */
function whatsappCode(string $phone): string
{
    $code = null;
    Bus::assertDispatched(SendWhatsAppCode::class, function (SendWhatsAppCode $job) use ($phone, &$code) {
        if ($job->phone === $phone) {
            $code = $job->code;
        }

        return $job->phone === $phone;
    });

    return $code;
}

/** El código de la copia por correo a esa dirección. */
function mailedCode(string $email): string
{
    $code = null;
    Mail::assertQueued(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $mail) use ($email, &$code) {
        if ($mail->hasTo($email)) {
            $code = $mail->code;
        }

        return $mail->hasTo($email);
    });

    return $code;
}

describe('teléfonos', function () {
    it('normaliza los celulares de Paraguay y de la región', function () {
        expect(Phone::mobile('0981 123 456'))->toBe('+595981123456')
            ->and(Phone::mobile('981123456'))->toBe('+595981123456')
            ->and(Phone::mobile('+595 981-123-456'))->toBe('+595981123456')
            ->and(Phone::mobile('+54 9 11 1234 5678'))->toBe('+5491112345678')
            // Un fijo no es un celular; un texto no es un número.
            ->and(Phone::mobile('021 123 456'))->toBeNull()
            ->and(Phone::normalize('021 123 456'))->toBe('+59521123456')
            ->and(Phone::mobile('llamar a la tarde'))->toBeNull()
            ->and(Phone::display('+595981123456'))->toBe('0981 123 456')
            ->and(Phone::searchFragment('0981 123'))->toBe('981123');
    });

    it('los tutores guardan el celular en formato internacional', function () {
        $organization = Organization::factory()->create();
        $guardian = app(CurrentOrganization::class)->run($organization, fn () => Guardian::factory()->create(['phone' => '0981 222 333']));

        expect($guardian->phone)->toBe('+595981222333')
            ->and($guardian->phone_display)->toBe('0981 222 333');
    });
});

describe('cuenta con el celular', function () {
    it('crea la cuenta y manda el código por WhatsApp', function () {
        $token = $this->postJson('/api/v1/auth/register', phonePayload())->assertCreated()->json('token');

        $user = User::query()->where('phone', '+595981123456')->sole();
        expect($user->email)->toBeNull()->and($user->isVerified())->toBeFalse();
        Mail::assertNothingQueued();

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertJsonPath('data.phone', '+595981123456')
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.verified', false);

        $this->withToken($token)->postJson('/api/v1/auth/verify', ['code' => whatsappCode('+595981123456')])->assertNoContent();

        expect($user->fresh()->phone_verified_at)->not->toBeNull()
            ->and(DB::table('verification_sends')->whereNotNull('verified_at')->count())->toBe(1);
        $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.verified', true);
    });

    it('valida el celular, el país y que no tenga cuenta', function () {
        User::factory()->create(['phone' => '+595981123456', 'phone_verified_at' => now()]);
        User::factory()->create(['email' => 'usada@test.com']);

        $this->postJson('/api/v1/auth/register', phonePayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Ya hay una cuenta con ese número. Ingresá con tu contraseña.');
        $this->postJson('/api/v1/auth/register', phonePayload(['phone' => '021 123 456']))
            ->assertJsonPath('errors.phone.0', 'Ingresá un número de celular válido.');
        $this->postJson('/api/v1/auth/register', phonePayload(['phone' => '+34 612 345 678']))
            ->assertJsonPath('errors.phone.0', 'Ese país no está habilitado. Creá la cuenta con tu correo.');
        $this->postJson('/api/v1/auth/register', phonePayload(['phone' => null]))
            ->assertJsonPath('errors.phone.0', 'Ingresá tu celular o tu correo.');
        // Con el celular, el correo es opcional, pero tampoco puede ser de otra cuenta.
        $this->postJson('/api/v1/auth/register', phonePayload(['phone' => '0981 999 888', 'email' => 'Usada@test.com']))
            ->assertJsonPath('errors.email.0', 'Ya hay una cuenta con ese correo. Ingresá con tu contraseña.');
    });

    it('una cuenta sin verificar no ocupa el número: el registro nuevo la reemplaza', function () {
        $old = User::factory()->unverified()->create(['phone' => '+595981123456', 'email' => null]);

        $this->postJson('/api/v1/auth/register', phonePayload(['name' => 'La dueña']))->assertCreated();

        expect(User::query()->find($old->id))->toBeNull()
            ->and(User::query()->where('phone', '+595981123456')->sole()->name)->toBe('La dueña');
    });

    it('las cuentas sin verificar se borran a las 24 horas', function () {
        $pending = User::factory()->unverified()->create(['phone' => '+595981123456', 'email' => null]);
        $recent = User::factory()->unverified()->create();
        $verified = User::factory()->create();
        $this->travel(25)->hours();
        $recent->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('accounts:prune-unverified')->assertSuccessful();

        expect(User::query()->find($pending->id))->toBeNull()
            ->and(User::query()->find($recent->id))->not->toBeNull()
            ->and(User::query()->find($verified->id))->not->toBeNull();
    });

    it('reenviar por correo, si la cuenta lo tiene', function () {
        $user = User::factory()->unverified()->create(['phone' => '+595981123456', 'email' => 'laura@test.com']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/verify/resend', ['channel' => 'mail'])->assertNoContent();
        Mail::assertQueued(EmailVerificationCodeMail::class, fn ($mail) => $mail->hasTo('laura@test.com'));

        $noEmail = User::factory()->unverified()->create(['phone' => '+595981555444', 'email' => null]);
        $this->actingAs($noEmail, 'sanctum')->postJson('/api/v1/auth/verify/resend', ['channel' => 'mail'])
            ->assertJsonPath('errors.channel.0', 'Tu cuenta no tiene correo.');
    });
});

describe('correo opcional: copia por correo de lo que va por WhatsApp', function () {
    it('con el celular y un correo, el código va por WhatsApp y una copia con su propio código por correo', function () {
        $token = $this->postJson('/api/v1/auth/register', phonePayload(['email' => 'Laura@Test.com']))->assertCreated()->json('token');

        $user = User::query()->where('phone', '+595981123456')->sole();
        expect($user->email)->toBe('laura@test.com');
        whatsappCode('+595981123456');
        Mail::assertQueued(EmailVerificationCodeMail::class, fn (EmailVerificationCodeMail $mail) => $mail->hasTo('laura@test.com')
            && $mail->whatsapp === '+595981123456'
            && $mail->confirmUrl !== null);
        expect(DB::table('verification_sends')->pluck('channel')->sort()->values()->all())->toBe(['mail', 'whatsapp']);

        // El código del correo confirma el correo, no el celular.
        $this->withToken($token)->postJson('/api/v1/auth/verify', ['code' => mailedCode('laura@test.com')])->assertNoContent();

        $user->refresh();
        expect($user->email_verified_at)->not->toBeNull()
            ->and($user->phone_verified_at)->toBeNull()
            ->and($user->mailableEmail())->toBe('laura@test.com')
            ->and(DB::table('verification_sends')->whereNull('verified_at')->count())->toBe(0);
    });

    it('con el código de WhatsApp el correo queda sin confirmar y no recibe copias de los avisos', function () {
        $token = $this->postJson('/api/v1/auth/register', phonePayload(['email' => 'laura@test.com']))->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/verify', ['code' => whatsappCode('+595981123456')])->assertNoContent();

        $user = User::query()->where('phone', '+595981123456')->sole();
        expect($user->phone_verified_at)->not->toBeNull()
            ->and($user->email_verified_at)->toBeNull()
            ->and($user->mailableEmail())->toBeNull();
    });

    it('un celular sin verificar no ocupa el número aunque la cuenta tenga el correo verificado', function () {
        $other = User::factory()->create(['phone' => '+595981123456', 'email' => 'otro@test.com']);

        $this->postJson('/api/v1/auth/register', phonePayload())->assertCreated();

        expect($other->fresh()->phone)->toBeNull()
            ->and($other->fresh()->email)->toBe('otro@test.com')
            ->and(User::query()->where('phone', '+595981123456')->sole()->name)->toBe('Laura Gómez');
    });

    it('un correo sin verificar tampoco ocupa el correo', function () {
        $other = User::factory()->unverified()->create(['phone' => '+595981555444', 'phone_verified_at' => now(), 'email' => 'laura@test.com']);

        $this->postJson('/api/v1/auth/register', phonePayload(['phone' => null, 'email' => 'laura@test.com']))->assertCreated();

        expect($other->fresh()->email)->toBeNull()
            ->and($other->fresh()->phone)->toBe('+595981555444')
            ->and(User::query()->where('email', 'laura@test.com')->sole()->name)->toBe('Laura Gómez');
    });

    it('para cambiar la contraseña, la copia va solo a un correo verificado y sirve su código', function () {
        User::factory()->create(['phone' => '+595981123456', 'phone_verified_at' => now(), 'email' => 'laura@test.com']);

        $this->postJson('/api/v1/auth/password/forgot', ['login' => '0981 123 456'])->assertNoContent();
        whatsappCode('+595981123456');
        Mail::assertQueued(EmailVerificationCodeMail::class, fn (EmailVerificationCodeMail $mail) => $mail->hasTo('laura@test.com')
            && $mail->purpose === 'reset' && $mail->whatsapp === '+595981123456' && $mail->confirmUrl === null);

        $this->postJson('/api/v1/auth/password/reset', [
            'login' => '0981123456', 'code' => mailedCode('laura@test.com'),
            'password' => 'nueva1234', 'password_confirmation' => 'nueva1234', 'device_name' => 'app',
        ])->assertCreated();
    });

    it('a un correo sin confirmar no le llegan códigos para cambiar la contraseña', function () {
        User::factory()->unverified()->create(['phone' => '+595981555444', 'phone_verified_at' => now(), 'email' => 'pedro@test.com']);

        $this->postJson('/api/v1/auth/password/forgot', ['login' => '0981 555 444'])->assertNoContent();
        $this->postJson('/api/v1/auth/password/forgot', ['login' => 'pedro@test.com'])->assertNoContent();

        Bus::assertDispatchedTimes(SendWhatsAppCode::class, 1);
        Mail::assertNotQueued(EmailVerificationCodeMail::class);
    });

    it('confirma el correo con el link y desde ahí recibe las copias', function () {
        $user = User::factory()->unverified()->create(['phone' => '+595981123456', 'phone_verified_at' => now(), 'email' => 'laura@test.com']);
        expect($user->mailableEmail())->toBeNull();

        $this->get(EmailConfirmation::url($user))->assertOk()->assertSee('Listo, confirmaste tu correo');

        expect($user->fresh()->email_verified_at)->not->toBeNull()
            ->and($user->fresh()->mailableEmail())->toBe('laura@test.com');
    });

    it('con el link vencido manda uno nuevo; si cambió el correo, no sirve', function () {
        $user = User::factory()->unverified()->create(['phone' => '+595981123456', 'phone_verified_at' => now(), 'email' => 'laura@test.com']);
        $url = EmailConfirmation::url($user);

        $this->travel(EmailConfirmation::VALID_DAYS + 1)->days();
        $this->get($url)->assertStatus(410)->assertSee('Te mandamos uno nuevo a laura@test.com.');
        Mail::assertQueued(ConfirmEmailMail::class, fn (ConfirmEmailMail $mail) => $mail->hasTo('laura@test.com'));

        $fresh = EmailConfirmation::url($user);
        $user->update(['email' => 'otra@test.com']);
        $this->get($fresh)->assertForbidden();
        $this->get(str_replace('signature=', 'signature=x', EmailConfirmation::url($user)))->assertForbidden();
        expect($user->fresh()->email_verified_at)->toBeNull();
    });
});

describe('olvidé mi contraseña', function () {
    it('cambia la contraseña con el código de WhatsApp y cierra las otras sesiones', function () {
        $user = User::factory()->create(['phone' => '+595981123456', 'password' => 'vieja1234']);
        $user->createToken('otro');

        $this->postJson('/api/v1/auth/password/forgot', ['login' => '0981 123 456'])->assertNoContent();
        $code = whatsappCode('+595981123456');

        $this->postJson('/api/v1/auth/password/reset', [
            'login' => '0981123456', 'code' => $code === '111111' ? '222222' : '111111',
            'password' => 'nueva1234', 'password_confirmation' => 'nueva1234', 'device_name' => 'app',
        ])->assertJsonPath('errors.code.0', 'El código no es correcto.');

        $this->postJson('/api/v1/auth/password/reset', [
            'login' => '0981123456', 'code' => $code,
            'password' => 'nueva1234', 'password_confirmation' => 'nueva1234', 'device_name' => 'app',
        ])->assertCreated()->assertJsonStructure(['token']);

        expect($user->tokens()->count())->toBe(1);
        $this->postJson('/api/v1/auth/token', ['login' => '0981123456', 'password' => 'nueva1234', 'device_name' => 'app'])
            ->assertCreated();
    });

    it('con el correo, el código va por correo', function () {
        User::factory()->create(['email' => 'laura@test.com']);

        $this->postJson('/api/v1/auth/password/forgot', ['login' => 'Laura@test.com'])->assertNoContent();

        Mail::assertQueued(EmailVerificationCodeMail::class, fn ($mail) => $mail->hasTo('laura@test.com') && $mail->purpose === 'reset');
        Bus::assertNotDispatched(SendWhatsAppCode::class);
    });

    it('no revela si hay una cuenta', function () {
        $this->postJson('/api/v1/auth/password/forgot', ['login' => '0981 000 111'])->assertNoContent();
        $this->postJson('/api/v1/auth/password/reset', [
            'login' => '0981 000 111', 'code' => '123456',
            'password' => 'nueva1234', 'password_confirmation' => 'nueva1234', 'device_name' => 'app',
        ])->assertJsonPath('errors.code.0', 'El código no es correcto.');

        Bus::assertNotDispatched(SendWhatsAppCode::class);
    });
});

describe('protección contra bots', function () {
    it('con Turnstile configurado exige el captcha', function () {
        config(['services.turnstile.secret_key' => 'secreto']);
        Http::fake(['challenges.cloudflare.com/*' => fn ($request) => Http::response([
            'success' => $request['response'] === 'token-bueno',
        ])]);

        $this->postJson('/api/v1/auth/register', phonePayload())
            ->assertJsonPath('errors.captcha_token.0', 'Confirmá que no sos un robot.');
        $this->postJson('/api/v1/auth/register', phonePayload(['captcha_token' => 'token-malo']))
            ->assertJsonPath('errors.captcha_token.0', 'Confirmá que no sos un robot.');
        $this->postJson('/api/v1/auth/register', phonePayload(['captcha_token' => 'token-bueno']))->assertCreated();
    });

    it('el campo trampa frena el envío', function () {
        $this->postJson('/api/v1/auth/password/forgot', ['login' => '0981 123 456', 'website' => 'http://spam.test'])
            ->assertJsonPath('errors.captcha_token.0', 'Confirmá que no sos un robot.');
    });

    it('limita los códigos por número: 1 por minuto, 3 por hora y 5 por día', function () {
        $user = User::factory()->unverified()->create(['phone' => '+595981123456', 'email' => null]);
        $resend = fn () => $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/verify/resend');

        $resend()->assertNoContent();
        $resend()->assertTooManyRequests()->assertJsonPath('message', 'Esperá un momento antes de pedir otro código.');

        $this->travel(61)->seconds();
        $resend()->assertNoContent();
        $this->travel(61)->seconds();
        $resend()->assertNoContent();
        $this->travel(61)->seconds();
        // Cuarto en la hora.
        $resend()->assertTooManyRequests();

        Bus::assertDispatchedTimes(SendWhatsAppCode::class, 3);
    });

    it('limita los códigos por IP aunque cambie el número', function () {
        foreach (range(1, 10) as $i) {
            $this->travel(13)->seconds(); // throttle de la ruta: 5 por minuto
            $this->postJson('/api/v1/auth/register', phonePayload(['phone' => '0981 '.(100000 + $i)]))->assertCreated();
        }
        $this->travel(13)->seconds();
        $this->postJson('/api/v1/auth/register', phonePayload(['phone' => '0981 200000']))->assertTooManyRequests();
    });

    it('tres códigos agotados en el día bloquean ese número', function () {
        $guard = app(CodeGuard::class);

        foreach (range(1, 3) as $i) {
            $guard->codeFailed('+595981123456');
        }

        expect(fn () => $guard->ensureCanSend('+595981123456'))->toThrow(TooManyCodes::class);
    });

    it('al llegar al tope diario se pausa WhatsApp y el código sale por correo', function () {
        Notification::fake();
        config(['services.whatsapp.daily_limit' => 2]);
        $admin = User::factory()->create(['is_super_admin' => true]);
        $users = User::factory()->unverified()->count(3)->sequence(
            ['phone' => '+595981000001', 'email' => null],
            ['phone' => '+595981000002', 'email' => null],
            ['phone' => '+595981000003', 'email' => 'tercero@test.com'],
        )->create();
        $send = app(SendVerificationCode::class);

        expect($send->handle($users[0]))->toBe('whatsapp')
            ->and($send->handle($users[1]))->toBe('whatsapp')
            ->and($send->handle($users[2]))->toBe('mail')
            ->and(CodeGuard::paused())->not->toBeNull();

        Mail::assertQueued(EmailVerificationCodeMail::class, fn ($mail) => $mail->hasTo('tercero@test.com'));
        Notification::assertSentTo($admin, WhatsAppPaused::class);

        // Sin correo, no hay cómo mandarlo.
        $noEmail = User::factory()->unverified()->create(['phone' => '+595981000004', 'email' => null]);
        $this->actingAs($noEmail, 'sanctum')->postJson('/api/v1/auth/verify/resend')
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'No pudimos mandar el código. Probá de nuevo más tarde.');

        app(CodeGuard::class)->resume();
        expect(CodeGuard::paused())->toBeNull();
    });

    it('muchos códigos que nadie usa pausan WhatsApp (posible abuso)', function () {
        Notification::fake();
        $now = now();
        DB::table('verification_sends')->insert(collect(range(1, 21))->map(fn ($i) => [
            'channel' => 'whatsapp', 'purpose' => 'verify', 'destination' => "+59598100{$i}",
            'created_at' => $now->copy()->subMinutes(30), 'updated_at' => $now,
        ])->all());

        $user = User::factory()->unverified()->create(['phone' => '+595981123456', 'email' => null]);
        app(SendVerificationCode::class)->handle($user);

        expect(CodeGuard::paused()['reason'])->toContain('se usaron 0');
    });
});

describe('WhatsApp', function () {
    it('sin token, el código va al log', function () {
        config(['services.whatsapp.dev_driver' => 'log']);
        expect(app(WhatsAppSender::class))->toBeInstanceOf(LogWhatsAppSender::class);
    });

    it('con la Cloud API manda la plantilla de autenticación', function () {
        config(['services.whatsapp.token' => 'meta-token', 'services.whatsapp.phone_number_id' => '123']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid']]])]);

        expect(app(WhatsAppSender::class))->toBeInstanceOf(CloudApiWhatsAppSender::class);
        app(WhatsAppSender::class)->sendCode('+595981123456', '042137');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/123/messages')
            && $request->hasHeader('Authorization', 'Bearer meta-token')
            && $request['to'] === '595981123456'
            && $request['template']['name'] === 'codigo_verificacion'
            && $request['template']['components'][0]['parameters'][0]['text'] === '042137');
    });
});

describe('WhatsApp en desarrollo', function () {
    it('con WHATSAPP_DEV_DRIVER=mail el código llega a Mailpit como un correo', function () {
        config(['services.whatsapp.dev_driver' => 'mail']);

        expect(app(WhatsAppSender::class))->toBeInstanceOf(MailWhatsAppSender::class);
        app(WhatsAppSender::class)->sendCode('+595981123456', '042137');

        Mail::assertSent(WhatsAppSimulatedMail::class, function (WhatsAppSimulatedMail $mail) {
            $mail->render();

            return $mail->hasTo('595981123456@whatsapp.test')
                && $mail->hasSubject('WhatsApp al 0981 123 456: tu código es 042137');
        });
        expect((new WhatsAppSimulatedMail('+595981123456', '042137'))->render())->toContain('042137</strong> es tu código');
    });
});
