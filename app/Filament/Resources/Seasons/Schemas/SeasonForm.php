<?php

namespace App\Filament\Resources\Seasons\Schemas;

use App\Enums\SeasonKind;
use App\Filament\Resources\Seasons\Support\SeasonPlanSteps;
use App\Filament\Support\Terms;
use App\Models\Program;
use App\Models\Season;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Edición de la temporada: datos y plan de cobro (para crearla está el asistente).
 * Los montos se cambian con "Cambiar monto" (nueva tarifa desde una fecha).
 */
class SeasonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // La organización la asigna el panel (tenant activo); nunca se elige a mano.
                Section::make('Datos')->schema([
                    TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                    CheckboxList::make('programs')
                        ->label(Terms::plural('program', 'Disciplinas'))
                        ->relationship('programs', 'name')
                        ->columns(3)
                        ->visible(fn () => Program::query()->count() > 1)
                        ->helperText('Si no elegís ninguna, vale para todas.'),
                    Radio::make('kind')->label('Duración')->options(SeasonKind::class)->inline()->required(),
                    Grid::make(2)->schema([
                        DatePicker::make('starts_on')->label('Empieza')->required(),
                        DatePicker::make('ends_on')->label('Termina')->required()->afterOrEqual('starts_on')
                            ->validationMessages(['after_or_equal' => 'Tiene que terminar después de empezar.']),
                    ]),
                ]),
                Section::make('Cobro')
                    ->description('Los montos se cambian con "Cambiar monto"; las cuotas ya emitidas no cambian.')
                    ->visible(fn (?Season $record) => SeasonPlanSteps::canPlan() && $record?->hasFeePlan())
                    ->schema([...SeasonPlanSteps::feeFields(), ...SeasonPlanSteps::issueFields()]),
            ]);
    }
}
