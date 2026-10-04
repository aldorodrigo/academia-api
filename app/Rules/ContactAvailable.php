<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Celular o correo libre para una cuenta nueva: no lo tiene verificado otra cuenta
 * (uno sin verificar no ocupa el dato, ver `User::owning()`).
 */
class ContactAvailable implements ValidationRule
{
    /**
     * @param  'phone'|'email'  $column
     */
    public function __construct(private string $column) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || blank($value)) {
            return;
        }

        $value = $this->column === 'phone' ? (Phone::mobile($value) ?? $value) : mb_strtolower(trim($value));

        if (User::query()->owning($this->column, $value)->exists()) {
            $fail($this->column === 'phone'
                ? 'Ya hay una cuenta con ese número. Ingresá con tu contraseña.'
                : 'Ya hay una cuenta con ese correo. Ingresá con tu contraseña.');
        }
    }
}
