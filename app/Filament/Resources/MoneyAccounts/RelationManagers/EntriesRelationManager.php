<?php

namespace App\Filament\Resources\MoneyAccounts\RelationManagers;

use App\Filament\Support\MoneyColumn;
use App\Models\LedgerEntry;
use App\Models\Payment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Movimientos del libro mayor: solo lectura (se anulan con un contra-movimiento).
 */
class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Movimientos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['reverses', 'reversedBy', 'creator', 'source' => fn (MorphTo $morph) => $morph->morphWith([Payment::class => ['creator']])]))
            ->columns([
                TextColumn::make('occurred_on')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('description')->label('Detalle')
                    ->description(fn (LedgerEntry $record) => match (true) {
                        $record->reverses !== null => 'Anula: '.$record->reverses->description,
                        $record->reversedBy !== null => 'Anulado',
                        default => null,
                    }),
                // Quién cobró (en los cobros) o quién registró el movimiento.
                TextColumn::make('by')->label('Cobró / registró')
                    ->state(fn (LedgerEntry $record) => ($record->source instanceof Payment ? $record->source->creator?->name : null) ?? $record->creator?->name),
                MoneyColumn::make('amount')->label('Monto')
                    ->color(fn (LedgerEntry $record) => $record->amount < 0 ? 'danger' : null),
            ])
            ->defaultSort('id', 'desc');
    }
}
