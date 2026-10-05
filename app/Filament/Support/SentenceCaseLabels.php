<?php

namespace App\Filament\Support;

use Illuminate\Support\Str;

/**
 * Títulos y menú con mayúscula solo al principio ("Gastos recurrentes", no "Gastos Recurrentes"),
 * como se escribe en español. Filament pasa los nombres de los recursos por ucwords().
 */
trait SentenceCaseLabels
{
    public static function getTitleCaseModelLabel(): string
    {
        return Str::ucfirst(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
