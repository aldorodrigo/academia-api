<?php

namespace App\Support\WhatsApp;

/**
 * Marca de Tuku en WhatsApp: el perfil del número que manda los códigos y la plantilla de autenticación
 * (los textos de la plantilla los pone Meta; se eligen el idioma, la recomendación de seguridad, el
 * vencimiento y el botón). Lo carga `php artisan whatsapp:brand`; ver docs/WHATSAPP.md.
 */
final class Branding
{
    /** Foto de perfil (640x640; WhatsApp la recorta en círculo). */
    public const PHOTO = 'brand/whatsapp-perfil.png';

    /** Presentación oficial (máx. 139 caracteres). */
    public const ABOUT = 'Tuku: cuotas, asistencia y avisos de clase para academias, clubes y escuelas. Todo en una app.';

    /** Categoría del perfil: educación. */
    public const VERTICAL = 'EDU';

    /** Texto del botón de la plantilla. */
    public const COPY_BUTTON = 'Copiar código';

    /**
     * Descripción del perfil (máx. 512 caracteres).
     */
    public static function description(): string
    {
        return 'Tuku es la app de academias, clubes, escuelas de formación y profesores particulares. '
            .'Por este número solo te mandamos los códigos para crear tu cuenta o cambiar tu contraseña: '
            .'no leemos los mensajes. Si necesitás ayuda, escribinos a '.config('tuku.email').'. Hecha en Paraguay.';
    }

    /**
     * Perfil del negocio (`POST /{phone-number-id}/whatsapp_business_profile`).
     *
     * @return array<string, mixed>
     */
    public static function profile(): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'about' => self::ABOUT,
            'description' => self::description(),
            'email' => config('tuku.email'),
            'websites' => [config('tuku.url')],
            'vertical' => self::VERTICAL,
        ];
    }

    /**
     * Plantilla de autenticación del código (`POST /{waba-id}/message_templates`): "*123456* es tu código de
     * verificación. Por tu seguridad, no lo compartas." + "Este código caduca en 15 minutos." + "Copiar código".
     *
     * @return array<string, mixed>
     */
    public static function codeTemplate(string $name, string $language, int $minutes): array
    {
        return [
            'name' => $name,
            'language' => $language,
            'category' => 'AUTHENTICATION',
            'message_send_ttl_seconds' => $minutes * 60,
            'components' => [
                ['type' => 'BODY', 'add_security_recommendation' => true],
                ['type' => 'FOOTER', 'code_expiration_minutes' => $minutes],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => self::COPY_BUTTON]]],
            ],
        ];
    }
}
