<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Celular válido. Con `forCodes`, además de un país al que se mandan códigos por WhatsApp.
 */
class MobilePhone implements ValidationRule
{
    public function __construct(private bool $forCodes = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $phone = Phone::mobile(is_string($value) ? $value : null);

        if ($phone === null) {
            $fail('Ingresá un número de celular válido.');

            return;
        }

        if ($this->forCodes && ! Phone::isAllowedCountry($phone)) {
            $fail('Ese país no está habilitado. Creá la cuenta con tu correo.');
        }
    }
}
