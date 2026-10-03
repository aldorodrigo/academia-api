<?php

namespace App\Support\WhatsApp;

use App\Support\Phone;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API de Meta: plantilla de autenticación con el botón "Copiar código".
 */
class CloudApiWhatsAppSender implements WhatsAppSender
{
    public function __construct(
        private string $token,
        private string $phoneNumberId,
        private string $template,
        private string $language,
        private string $apiVersion,
    ) {}

    public function sendCode(string $phone, string $code): void
    {
        Http::withToken($this->token)
            ->timeout(15)
            ->post("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => Phone::digits($phone),
                'type' => 'template',
                'template' => [
                    'name' => $this->template,
                    'language' => ['code' => $this->language],
                    'components' => [
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                        [
                            'type' => 'button',
                            'sub_type' => 'url',
                            'index' => '0',
                            'parameters' => [['type' => 'text', 'text' => $code]],
                        ],
                    ],
                ],
            ])
            ->throw();
    }
}
