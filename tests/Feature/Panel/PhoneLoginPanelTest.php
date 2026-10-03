<?php

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RegisterAccount;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Platform\Widgets\VerificationCodes;
use App\Jobs\SendWhatsAppCode;
use App\Models\User;
use App\Support\Verification\CodeGuard;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    filament()->setCurrentPanel('admin');
});

it('el panel acepta el celular o el correo para entrar', function () {
    $user = User::factory()->create(['phone' => '+595981123456', 'email' => 'laura@test.com', 'password' => 'secreta123']);

    Livewire::test(Login::class)
        ->fillForm(['login' => '0981 123 456', 'password' => 'secreta123'])
        ->call('authenticate')
        ->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    Livewire::test(Login::class)
        ->fillForm(['login' => 'Laura@Test.com', 'password' => 'secreta123'])
        ->call('authenticate')
        ->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    Livewire::test(Login::class)
        ->fillForm(['login' => '0981 123 456', 'password' => 'otra'])
        ->call('authenticate')
        ->assertHasFormErrors(['login']);
});

it('"Olvidé mi contraseña" del panel cambia la contraseña con el código y entra', function () {
    Bus::fake([SendWhatsAppCode::class]);
    $user = User::factory()->create(['phone' => '+595981123456', 'password' => 'vieja1234']);

    $page = Livewire::test(RequestPasswordReset::class)
        ->fillForm(['login' => '0981 123 456'])
        ->tap(fn () => $this->travel(3)->seconds())
        ->call('request')
        ->assertSet('codeSent', true);

    $code = null;
    Bus::assertDispatched(SendWhatsAppCode::class, function (SendWhatsAppCode $job) use (&$code) {
        $code = $job->code;

        return $job->phone === '+595981123456';
    });

    $page->fillForm(['login' => '0981 123 456', 'code' => $code, 'password' => 'nueva1234', 'passwordConfirmation' => 'nueva1234'])
        ->call('request')
        ->assertHasNoFormErrors()
        ->assertRedirect(filament()->getUrl());

    expect(Hash::check('nueva1234', $user->fresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('el super admin ve los códigos del día y pausa o reanuda WhatsApp', function () {
    Notification::fake();
    filament()->setCurrentPanel('platform');
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(VerificationCodes::class)
        ->assertSee('Códigos de verificación')
        ->callAction('toggleWhatsapp');
    expect(CodeGuard::paused()['reason'])->toBe('Pausado a mano desde el panel de plataforma.');

    Livewire::test(VerificationCodes::class)
        ->assertSee('WhatsApp pausado')
        ->callAction('toggleWhatsapp');
    expect(CodeGuard::paused())->toBeNull();
});

it('con Turnstile, cada envío pide un token nuevo (el anterior ya se usó)', function () {
    config(['services.turnstile.secret_key' => 'secreto', 'services.turnstile.site_key' => '1x00000000000000000000AA']);
    $used = [];
    Http::fake(['challenges.cloudflare.com/*' => function ($request) use (&$used) {
        $ok = ! in_array($request['response'], $used, true);
        $used[] = $request['response'];

        return Http::response(['success' => $ok]);
    }]);
    // El número ya tiene un código recién mandado: el primer intento lo frena el límite por minuto.
    app(CodeGuard::class)->record(null, 'whatsapp', 'verify', '+595981123456');

    $page = Livewire::test(RegisterAccount::class)
        ->assertSee('challenges.cloudflare.com', escape: false)
        ->fillForm([
            'name' => 'Laura Gómez',
            'phone' => '0981 123 456',
            'password' => 'secreta123',
            'passwordConfirmation' => 'secreta123',
            'terms' => true,
            'captcha_token' => 'token-1',
        ])
        ->tap(fn () => $this->travel(3)->seconds())
        ->call('register')
        ->assertHasErrors(['data.phone'])
        ->assertDispatched('turnstile-reset')
        ->assertSet('data.captcha_token', null);

    // Con un token nuevo (el widget se reinició), pasado el minuto, se crea.
    $this->travel(61)->seconds();
    Bus::fake([SendWhatsAppCode::class]);
    $page->fillForm(['captcha_token' => 'token-2'])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::query()->where('phone', '+595981123456')->exists())->toBeTrue();
});
