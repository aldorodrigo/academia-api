<?php

namespace App\Actions\Auth;

use App\Jobs\SendWhatsAppCode;
use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use App\Support\Verification\CodeGuard;
use App\Support\Verification\TooManyCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Manda un código de 6 dígitos por WhatsApp (si la cuenta tiene celular) o por correo, para verificar
 * la cuenta o para cambiar la contraseña. Reemplaza al anterior del mismo propósito. Pasa por
 * `CodeGuard`; si WhatsApp está pausado, usa el correo cuando la cuenta lo tiene.
 */
class SendVerificationCode
{
    public const VALID_MINUTES = 15;

    public const VERIFY = 'verify';

    public const RESET = 'reset';

    public function __construct(private CodeGuard $guard) {}

    /**
     * @param  'whatsapp'|'mail'|null  $channel  por defecto WhatsApp si hay celular
     * @return 'whatsapp'|'mail' el canal por el que salió
     */
    public function handle(User $user, string $purpose = self::VERIFY, ?string $channel = null): ?string
    {
        if ($purpose === self::VERIFY && $user->isVerified()) {
            return null;
        }

        $channel ??= filled($user->phone) ? 'whatsapp' : 'mail';

        if ($channel === 'mail' && blank($user->email)) {
            throw ValidationException::withMessages(['channel' => 'Tu cuenta no tiene correo.']);
        }

        if ($channel === 'whatsapp') {
            $this->guard->ensureCanSend($user->phone, $user);

            if (! $this->guard->whatsappAvailable()) {
                if (blank($user->email)) {
                    throw TooManyCodes::unavailable();
                }
                $channel = 'mail';
            }
        }

        $destination = $channel === 'whatsapp' ? $user->phone : $user->email;

        if ($channel === 'mail') {
            $this->guard->ensureCanSend($destination, $user);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('verification_codes')->updateOrInsert(
            ['user_id' => $user->id, 'purpose' => $purpose],
            [
                'channel' => $channel,
                'destination' => $destination,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::VALID_MINUTES),
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        if ($channel === 'whatsapp') {
            SendWhatsAppCode::dispatch($destination, $code);
        } else {
            Mail::to($destination)->queue(new EmailVerificationCodeMail($user->name, $code, $purpose));
        }

        $this->guard->record($user, $channel, $purpose, $destination);

        return $channel;
    }
}
