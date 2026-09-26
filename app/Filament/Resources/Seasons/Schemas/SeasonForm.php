<?php

namespace App\Filament\Resources\Seasons\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SeasonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // La organización la asigna el panel (tenant activo); nunca se elige a mano.
                TextInput::make('name')
                    ->label('Nombre')
                    ->placeholder('2026')
                    ->required()
                    ->maxLength(255),
                DatePicker::make('starts_on')
                    ->label('Inicio')
                    ->required(),
                DatePicker::make('ends_on')
                    ->label('Fin')
                    ->required()
                    ->afterOrEqual('starts_on'),
                Toggle::make('is_current')
                    ->label('Temporada actual'),
            ]);
    }
}
