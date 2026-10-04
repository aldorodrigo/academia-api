<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // WhatsApp Cloud API de Meta: solo códigos de verificación (plantilla de autenticación).
    // Sin token, los códigos van al log (desarrollo).
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        // Para `whatsapp:brand`: cuenta de WhatsApp Business (crea la plantilla) y app de Meta (sube la foto).
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'app_id' => env('WHATSAPP_APP_ID'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v23.0'),
        'code_template' => env('WHATSAPP_CODE_TEMPLATE', 'codigo_verificacion'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'es'),
        // Sin token (desarrollo): `log` (storage/logs/laravel.log) o `mail` (llegan a Mailpit).
        'dev_driver' => env('WHATSAPP_DEV_DRIVER', 'log'),
        // Tope de códigos por día de toda la plataforma: al llegar, se pausa WhatsApp.
        'daily_limit' => (int) env('WHATSAPP_DAILY_LIMIT', 300),
        // Precio por código entregado en Paraguay ("Rest of Latin America"), para el panel.
        'cost_per_code' => (float) env('WHATSAPP_COST_PER_CODE', 0.0113),
        // Países (ISO) a los que se mandan códigos.
        'allowed_countries' => explode(',', (string) env('WHATSAPP_ALLOWED_COUNTRIES', 'PY,AR,BR,UY,BO')),
    ],

    // Cloudflare Turnstile (anti-bots) al pedir códigos. Sin clave secreta no se valida.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

];
