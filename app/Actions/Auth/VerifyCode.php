<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Verification\CodeGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Comprueba el código: vence a los 15 minutos y admite 5 intentos (entre el de WhatsApp y su copia por
 * correo). Al acertar, marca como verificado el canal por el que llegó ese código (celular o correo).
 */
class VerifyCode
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private CodeGuard $guard) {}

    public function handle(User $user, string $code, string $purpose = SendVerificationCode::VERIFY, string $field = 'code'): void
    {
        if ($purpose === SendVerificationCode::VERIFY && $user->isVerified()) {
            return;
        }

        $row = DB::table('verification_codes')->where('user_id', $user->id)->where('purpose', $purpose)->first();
        $fail = fn (string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($row === null || $row->attempts >= self::MAX_ATTEMPTS) {
            $fail($row === null ? 'Pedí un código nuevo.' : 'Demasiados intentos. Pedí un código nuevo.');
        }

        if (now()->gt($row->expires_at)) {
            $fail('El código venció. Pedí uno nuevo.');
        }

        $code = trim($code);
        [$channel, $destination] = match (true) {
            Hash::check($code, $row->code_hash) => [$row->channel, $row->destination],
            $row->copy_code_hash !== null && Hash::check($code, $row->copy_code_hash) => ['mail', $row->copy_destination],
            default => [null, null],
        };

        if ($channel === null) {
            DB::table('verification_codes')->where('id', $row->id)->increment('attempts');

            if ($row->attempts + 1 >= self::MAX_ATTEMPTS) {
                $this->guard->codeFailed($row->destination);
            }

            $fail('El código no es correcto.');
        }

        if ($channel === 'whatsapp' && $user->phone === $destination) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        } elseif ($channel === 'mail' && $user->email === $destination) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        DB::table('verification_codes')->where('id', $row->id)->delete();
        $this->guard->markVerified($row->destination);

        if ($row->copy_destination !== null) {
            $this->guard->markVerified($row->copy_destination);
        }
    }
}
