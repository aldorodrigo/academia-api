<?php

namespace App\Support\Verification;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile: prueba de que hay una persona antes de mandar un código.
 * Sin clave secreta (desarrollo y tests) no se valida.
 */
class Captcha
{
    public static function enabled(): bool
    {
        return filled(config('services.turnstile.secret_key'));
    }

    public function passes(?string $token, ?string $ip = null): bool
    {
        if (! self::enabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            return (bool) Http::asForm()
                ->timeout(10)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ])
                ->json('success', false);
        } catch (Throwable) {
            return false;
        }
    }
}
