<?php

namespace App\Filament\Support;

use App\Support\Phone;
use Closure;
use Filament\Forms\Components\TextInput;

/**
 * "Celular (WhatsApp) o correo" para invitar: con `@` es un correo, si no un celular.
 */
final class ContactField
{
    public static function make(string $name = 'contact'): TextInput
    {
        return TextInput::make($name)
            ->label('Celular (WhatsApp) o correo')
            ->placeholder('0981 123 456')
            ->required()
            ->maxLength(255)
            ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                $value = trim((string) $value);

                if (Phone::looksLikeEmail($value)) {
                    if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                        $fail('El correo no es válido.');
                    }
                } elseif (Phone::mobile($value) === null) {
                    $fail('Ingresá un número de celular válido.');
                }
            });
    }

    /**
     * @return array{phone: ?string, email: ?string}
     */
    public static function split(?string $value): array
    {
        $value = trim((string) $value);

        return Phone::looksLikeEmail($value)
            ? ['phone' => null, 'email' => mb_strtolower($value)]
            : ['phone' => Phone::mobile($value), 'email' => null];
    }
}
