<?php

namespace App\Filament\Support;

use App\Enums\Gender;
use Filament\Forms\Components\Select;

/**
 * Género opcional de una persona (alumno, personal): solo para nombrarla bien ("Técnica", "Jugadora").
 */
class GenderField
{
    public static function make(string $name = 'gender'): Select
    {
        return Select::make($name)
            ->label('Género (opcional)')
            ->options(Gender::class)
            ->placeholder('Sin especificar')
            ->helperText('Para nombrarla bien en los textos (por ejemplo, "Técnica" o "Jugadora").');
    }
}
