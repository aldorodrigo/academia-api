<?php

use App\Mail\EmailVerificationCodeMail;
use App\Mail\WhatsAppSimulatedMail;
use App\Models\User;
use App\Notifications\WhatsAppPaused;
use App\Support\WhatsApp\Branding;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('correos con la marca Tuku', function () {
    it('el código por correo: logo, colores, pie y, en la copia, el aviso de WhatsApp y el botón para confirmar', function () {
        $html = (new EmailVerificationCodeMail('Laura Gómez', '042137', 'verify', '+595981123456', 'https://api.test/confirmar'))->render();

        expect($html)->toContain('brand/correo/tuku-logo.png')
            ->toContain('brand/correo/tuku-hola.png')
            ->toContain('Hola, Laura')
            ->toContain('042137')
            ->toContain('También te mandamos un código por WhatsApp al 0981 123 456')
            ->toContain('https://api.test/confirmar')
            ->toContain('Confirmar mi correo')
            ->toContain('Hecha en Paraguay')
            ->toContain('tukuha.app')
            ->toContain('Baloo+2')
            ->toContain('#167a3a')
            ->not->toContain('Laravel');
    });

    it('para cambiar la contraseña no lleva mascota ni botón de confirmar', function () {
        $mail = new EmailVerificationCodeMail('Laura Gómez', '042137', 'reset');

        expect($mail->render())->not->toContain('tuku-hola.png')->not->toContain('Confirmar mi correo')
            ->and($mail->envelope()->subject)->toBe('Tu código para cambiar la contraseña: 042137');
    });

    it('las notificaciones por correo van en español y con la marca', function () {
        $admin = User::factory()->create(['name' => 'Rodrigo Ruiz']);

        $html = (string) (new WhatsAppPaused('Se llegó al tope diario.'))->toMail($admin)->render();

        expect($html)->toContain('<h1')->toContain('Hola')
            ->toContain('Si el botón "Panel de plataforma" no funciona')
            ->toContain('brand/correo/tuku-logo.png')
            ->not->toContain('Hello')
            ->not->toContain('Regards');
    });
});

describe('WhatsApp con la marca Tuku', function () {
    it('el WhatsApp simulado se ve como el chat con Tuku', function () {
        $html = (new WhatsAppSimulatedMail('+595981123456', '042137'))->render();

        expect($html)->toContain('brand/whatsapp-perfil.png')
            ->toContain('>Tuku<')
            ->toContain('042137</strong> es tu código de verificación')
            ->toContain('Este código caduca en 15 minutos.')
            ->toContain('Copiar código')
            ->toContain(Branding::ABOUT);
    });

    it('los textos del perfil entran en los límites de WhatsApp', function () {
        expect(mb_strlen(Branding::ABOUT))->toBeLessThanOrEqual(139)
            ->and(mb_strlen(Branding::description()))->toBeLessThanOrEqual(512)
            ->and(file_exists(public_path(Branding::PHOTO)))->toBeTrue()
            ->and(getimagesize(public_path(Branding::PHOTO)))->toMatchArray([0 => 640, 1 => 640]);
    });

    it('whatsapp:brand carga el perfil con la foto y la plantilla del código', function () {
        config([
            'services.whatsapp.token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.business_account_id' => '456',
            'services.whatsapp.app_id' => '789',
        ]);
        Http::fake([
            'graph.facebook.com/*/789/uploads*' => Http::response(['id' => 'upload:abc']),
            'graph.facebook.com/*/upload:abc' => Http::response(['h' => 'handle-foto']),
            'graph.facebook.com/*/123/whatsapp_business_profile' => Http::response(['success' => true]),
            'graph.facebook.com/*/456/message_templates' => Http::response(['id' => '1', 'status' => 'PENDING', 'category' => 'AUTHENTICATION']),
        ]);

        $this->artisan('whatsapp:brand')
            ->expectsOutput('Perfil de WhatsApp actualizado con la foto.')
            ->expectsOutput('Plantilla "codigo_verificacion" enviada a Meta (estado: PENDING).')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/upload:abc')
            && $request->hasHeader('Authorization', 'OAuth meta-token')
            && $request->hasHeader('file_offset', '0'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/123/whatsapp_business_profile')
            && $request['about'] === Branding::ABOUT
            && $request['vertical'] === 'EDU'
            && $request['websites'] === ['https://tukuha.app']
            && $request['email'] === 'hola@tukuha.app'
            && $request['profile_picture_handle'] === 'handle-foto');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/456/message_templates')
            && $request['category'] === 'AUTHENTICATION'
            && $request['language'] === 'es'
            && $request['components'][0] === ['type' => 'BODY', 'add_security_recommendation' => true]
            && $request['components'][1] === ['type' => 'FOOTER', 'code_expiration_minutes' => 15]
            && $request['components'][2]['buttons'][0] === ['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copiar código']);
    });

    it('sin la app ni la cuenta de WhatsApp Business, solo los textos del perfil', function () {
        config(['services.whatsapp.token' => 'meta-token', 'services.whatsapp.phone_number_id' => '123']);
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $this->artisan('whatsapp:brand')
            ->expectsOutputToContain('Sin WHATSAPP_APP_ID no se sube la foto')
            ->expectsOutput('Sin WHATSAPP_BUSINESS_ACCOUNT_ID no se crea la plantilla del código.')
            ->assertSuccessful();
        Http::assertSentCount(1);
    });

    it('sin token no hace nada y muestra el error de Meta si lo hay', function () {
        $this->artisan('whatsapp:brand')->expectsOutput('Falta WHATSAPP_TOKEN o WHATSAPP_PHONE_NUMBER_ID.')->assertFailed();

        config(['services.whatsapp.token' => 'meta-token', 'services.whatsapp.phone_number_id' => '123']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid parameter']], 400)]);
        $this->artisan('whatsapp:brand --profile')->expectsOutput('Meta respondió: Invalid parameter')->assertFailed();
    });
});
