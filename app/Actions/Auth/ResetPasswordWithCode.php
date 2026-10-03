<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Phone;
use App\Support\Verification\CodeGuard;
use Illuminate\Validation\ValidationException;

/**
 * "Olvidé mi contraseña" con código: por WhatsApp si se ingresa el celular, por correo si se ingresa
 * el correo. No revela si hay una cuenta.
 */
class ResetPasswordWithCode
{
    public function __construct(
        private SendVerificationCode $sendCode,
        private VerifyCode $verify,
        private CodeGuard $guard,
    ) {}

    public function request(string $login): void
    {
        $this->guard->ensureIpAllowed();

        $user = User::findByLogin($login);

        if ($user === null) {
            $this->guard->hitIp();

            return;
        }

        $this->sendCode->handle($user, SendVerificationCode::RESET, Phone::looksLikeEmail($login) ? 'mail' : 'whatsapp');
    }

    /**
     * Cambia la contraseña, marca el canal como verificado y cierra las otras sesiones.
     */
    public function reset(string $login, string $code, string $password, string $field = 'code'): User
    {
        $user = User::findByLogin($login)
            ?? throw ValidationException::withMessages([$field => 'El código no es correcto.']);

        $this->verify->handle($user, $code, SendVerificationCode::RESET, $field);

        $user->forceFill(['password' => $password])->save();
        $user->tokens()->delete();

        return $user;
    }
}
