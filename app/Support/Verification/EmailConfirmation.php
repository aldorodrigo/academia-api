<?php

namespace App\Support\Verification;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Link "Confirmar mi correo" para el correo opcional de una cuenta con celular: al tocarlo, el correo queda
 * verificado y empiezan a llegarle las copias de los avisos (ver `User::mailableEmail()`).
 */
final class EmailConfirmation
{
    public const VALID_DAYS = 7;

    public static function url(User $user): string
    {
        return URL::temporarySignedRoute('email.confirm', now()->addDays(self::VALID_DAYS), [
            'user' => $user->id,
            'hash' => self::hash((string) $user->email),
        ]);
    }

    /**
     * El link sirve solo para el correo al que se mandó.
     */
    public static function hash(string $email): string
    {
        return sha1(mb_strtolower($email));
    }
}
