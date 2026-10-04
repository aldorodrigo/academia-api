<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Filament\Support\MoneyColumn;
use App\Filament\Support\WaiveChargeAction;
use App\Models\Charge;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuenta del jugador: sus cargos con ajustes y el saldo. No se cargan ni editan cargos acá (se opera
 * desde Cargos); sí se condona lo pendiente (por ejemplo, al darle de baja), con el permiso.
 */
class ChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    protected static ?string $title = 'Cuenta';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $balance = (int) $ownerRecord->charges()->notVoided()->with('allocations.payment')->get()
            ->sum(fn (Charge $charge) => $charge->pendingAmount());

        return $balance > 0 ? Money::pyg($balance)->format() : null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['adjustments', 'organization', 'allocations.payment']))
            ->description(fn () => ($credit = $this->getOwnerRecord()->family?->credit()) ? 'Saldo a favor de la familia: '.Money::pyg($credit)->format() : null)
            ->columns([
                TextColumn::make('description')->label('Concepto')
                    ->description(fn (Charge $record) => $record->adjustments->pluck('label')->join(' · ') ?: null),
                TextColumn::make('due_on')->label('Vence')->date('d/m/Y'),
                TextColumn::make('status')->label('Estado')->badge()->state(fn (Charge $record) => $record->status()),
                MoneyColumn::make('final_amount')->label('Monto'),
                MoneyColumn::make('pending')->label('Pendiente')
                    ->state(fn (Charge $record) => $record->isVoided() ? null : $record->pendingAmount()),
            ])
            ->defaultSort('due_on', 'desc')
            ->recordActions([WaiveChargeAction::make()])
            ->toolbarActions([WaiveChargeAction::bulk()]);
    }
}
