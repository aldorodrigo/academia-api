<?php

namespace App\Console\Commands;

use App\Actions\Auth\SendVerificationCode;
use App\Support\WhatsApp\Branding;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Carga la marca de Tuku en el número de WhatsApp (Cloud API de Meta): perfil del negocio con la foto y
 * la plantilla de autenticación de los códigos. Se corre una vez al configurar el número (y de nuevo si
 * cambian los textos). El nombre visible ("Tuku") se pide en el Administrador de WhatsApp.
 */
#[Signature('whatsapp:brand {--profile : Solo el perfil del negocio} {--template : Solo la plantilla del código}')]
#[Description('Carga el perfil de Tuku (foto, presentación, sitio) y la plantilla del código en WhatsApp')]
class BrandWhatsApp extends Command
{
    public function handle(): int
    {
        if (blank(config('services.whatsapp.token')) || blank(config('services.whatsapp.phone_number_id'))) {
            $this->error('Falta WHATSAPP_TOKEN o WHATSAPP_PHONE_NUMBER_ID.');

            return self::FAILURE;
        }

        $both = ! $this->option('profile') && ! $this->option('template');

        try {
            if ($both || $this->option('profile')) {
                $this->profile();
            }

            if ($both || $this->option('template')) {
                $this->template();
            }
        } catch (RequestException $exception) {
            $this->error('Meta respondió: '.($exception->response->json('error.message') ?? $exception->getMessage()));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function profile(): void
    {
        $profile = Branding::profile();
        $photo = $this->uploadPhoto();

        if ($photo !== null) {
            $profile['profile_picture_handle'] = $photo;
        }

        $this->graph()->post(config('services.whatsapp.phone_number_id').'/whatsapp_business_profile', $profile)->throw();

        $this->info('Perfil de WhatsApp actualizado'.($photo !== null ? ' con la foto.' : '. Sin WHATSAPP_APP_ID no se sube la foto: cargala a mano.'));
    }

    /**
     * Sube la foto con la API de subida reanudable (necesita el id de la app de Meta) y devuelve su handle.
     */
    private function uploadPhoto(): ?string
    {
        $appId = config('services.whatsapp.app_id');

        if (blank($appId)) {
            return null;
        }

        $file = public_path(Branding::PHOTO);
        $session = $this->graph()->post("{$appId}/uploads?".http_build_query([
            'file_name' => basename($file),
            'file_length' => filesize($file),
            'file_type' => 'image/png',
        ]))->throw()->json('id');

        return Http::withHeaders(['Authorization' => 'OAuth '.config('services.whatsapp.token'), 'file_offset' => '0'])
            ->baseUrl($this->baseUrl())
            ->timeout(30)
            ->withBody((string) file_get_contents($file), 'image/png')
            ->post($session)
            ->throw()
            ->json('h');
    }

    private function template(): void
    {
        $account = config('services.whatsapp.business_account_id');

        if (blank($account)) {
            $this->warn('Sin WHATSAPP_BUSINESS_ACCOUNT_ID no se crea la plantilla del código.');

            return;
        }

        $name = config('services.whatsapp.code_template');
        $response = $this->graph()->post("{$account}/message_templates", Branding::codeTemplate(
            $name,
            config('services.whatsapp.template_language'),
            SendVerificationCode::VALID_MINUTES,
        ))->throw();

        $this->info("Plantilla \"{$name}\" enviada a Meta (estado: ".$response->json('status', 'PENDING').').');
    }

    private function graph(): PendingRequest
    {
        return Http::withToken(config('services.whatsapp.token'))->baseUrl($this->baseUrl())->timeout(30)->acceptJson();
    }

    private function baseUrl(): string
    {
        return 'https://graph.facebook.com/'.config('services.whatsapp.api_version');
    }
}
