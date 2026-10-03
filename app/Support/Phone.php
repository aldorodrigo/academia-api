<?php

namespace App\Support;

use libphonenumber\PhoneNumberType;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/**
 * Teléfonos: se guardan en formato internacional (E.164, `+595981123456`) y se aceptan en
 * cualquier forma habitual (`0981 123 456`, `981123456`, `+595 981 123456`). Paraguay por defecto.
 */
final class Phone
{
    public const DEFAULT_COUNTRY = 'PY';

    /**
     * E.164 si es un número válido (de cualquier tipo); si no, null.
     */
    public static function normalize(?string $value): ?string
    {
        $number = self::parse($value);

        return $number?->formatE164();
    }

    /**
     * E.164 si es un celular válido (el que puede tener WhatsApp); si no, null.
     */
    public static function mobile(?string $value): ?string
    {
        $number = self::parse($value);

        if ($number === null || ! $number->isOfType([PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE])) {
            return null;
        }

        return $number->formatE164();
    }

    /**
     * País (ISO de 2 letras) de un número.
     */
    public static function country(?string $value): ?string
    {
        return self::parse($value)?->getCountry();
    }

    /**
     * Países a los que se mandan códigos por WhatsApp (config `services.whatsapp.allowed_countries`).
     */
    public static function isAllowedCountry(string $e164): bool
    {
        return in_array(self::country($e164), config('services.whatsapp.allowed_countries'), true);
    }

    /**
     * Para mostrar: "0981 123 456" en Paraguay; el resto, internacional.
     */
    public static function display(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (preg_match('/^\+595(\d{3})(\d{3})(\d{3})$/', $value, $m)) {
            return "0{$m[1]} {$m[2]} {$m[3]}";
        }

        return self::parse($value)?->formatInternational() ?? $value;
    }

    /**
     * Para buscar por teléfono: los dígitos sin el 0 inicial ("0981 123" → "981123"), que están
     * dentro del formato internacional guardado.
     */
    public static function searchFragment(string $search): string
    {
        return ltrim(preg_replace('/\D/', '', $search), '0');
    }

    /**
     * Número para los links de WhatsApp (`wa.me/595981123456`).
     */
    public static function digits(string $e164): string
    {
        return preg_replace('/\D/', '', $e164);
    }

    /**
     * Lo que se ingresa para entrar: con `@` es un correo, si no un celular.
     */
    public static function looksLikeEmail(string $login): bool
    {
        return str_contains($login, '@');
    }

    private static function parse(?string $value): ?PhoneNumber
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/[^\d\s\-+().]/', $value)) {
            return null;
        }

        try {
            $number = new PhoneNumber($value, self::DEFAULT_COUNTRY);

            return $number->isValid() ? $number : null;
        } catch (Throwable) {
            return null;
        }
    }
}
