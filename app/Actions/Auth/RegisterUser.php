<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Phone;

/**
 * Alta de una cuenta sin organizaciones, con el celular (código por WhatsApp) o con el correo
 * (código por correo). Queda sin verificar hasta que ingresa el código. Una cuenta sin verificar
 * no ocupa el número ni el correo: el registro nuevo la reemplaza.
 */
class RegisterUser
{
    /** Versión de los términos y la política de datos que se aceptan al registrarse. */
    public const TERMS_VERSION = '2026-10';

    public function __construct(private SendVerificationCode $sendCode) {}

    /**
     * @param  array{name: string, phone?: string|null, email?: string|null, password: string}  $data
     */
    public function handle(array $data): User
    {
        $phone = filled($data['phone'] ?? null) ? Phone::mobile($data['phone']) : null;
        $email = $phone === null && filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;

        User::query()
            ->pending()
            ->where(fn ($query) => $phone ? $query->where('phone', $phone) : $query->where('email', $email))
            ->get()
            ->each->delete();

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
