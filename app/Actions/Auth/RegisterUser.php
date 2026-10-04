<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Phone;

/**
 * Alta de una cuenta sin organizaciones, con el celular (código por WhatsApp y, si deja un correo, una copia
 * por correo) o con el correo (código por correo). Queda sin verificar hasta que ingresa el código. Un dato
 * sin verificar no ocupa el número ni el correo: el registro nuevo lo libera.
 */
class RegisterUser
{
    /** Versión de los términos y la política de datos que se aceptan al registrarse. */
    public const TERMS_VERSION = '2026-10';

    public function __construct(private SendVerificationCode $sendCode) {}

    /**
     * @param  array{name: string, phone?: string|null, email?: string|null, password: string}  $data  con el celular, el correo es opcional
     */
    public function handle(array $data): User
    {
        $phone = filled($data['phone'] ?? null) ? Phone::mobile($data['phone']) : null;
        $email = filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;

        User::releaseContacts($phone, $email);

        $user = User::query()->create([
            'name' => trim($data['name']),
            'phone' => $phone,
            'email' => $email,
            'password' => $data['password'],
            'terms_accepted_at' => now(),
            'terms_version' => self::TERMS_VERSION,
        ]);

        $this->sendCode->handle($user);

        return $user;
    }
}
