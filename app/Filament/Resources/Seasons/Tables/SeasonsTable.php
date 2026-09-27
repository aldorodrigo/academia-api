<?php

namespace App\Filament\Resources\Seasons\Tables;

use App\Enums\SeasonStatus;
use App\Filament\Resources\Seasons\Support\SeasonActions;
use App\Filament\Support\Terms;
use App\Models\Season;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SeasonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('programs')->withCount(['charges' => fn (Builder $charges) => $charges->whereNull('voided_at')]))
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable(),
                TextColumn::make('programs.name')->label(ucfirst(Terms::plural('program', 'Disciplina')))->badge()->placeholder('Todas'),
                TextColumn::make('kind')->label('Duración'),
                TextColumn::make('starts_on')->label('Fechas')->sortable()
                    ->formatStateUsing(fn (Season $record) => $record->starts_on->format('d/m/Y').' – '.$record->ends_on->format('d/m/Y')),
                TextColumn::make('status')->label('Estado')->badge()
                    ->state(fn (Season $record): SeasonStatus => $record->status()),
                TextColumn::make('fee_frequency')->label('Cuota')->badge()
                    ->placeholder('Sin plan de cobro')
                    ->color('gray'),
                TextColumn::make('charges_count')->label('Cuotas emitidas')->numeric(),
            ])
            ->defaultSort('starts_on', 'desc')
            ->recordActions([
                SeasonActions::configurePlan(),
                EditAction::make(),
            ]);
    }
}
