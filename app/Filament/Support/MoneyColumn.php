<?php

namespace App\Filament\Support;

use App\Support\Money;
use Filament\Tables\Columns\TextColumn;

/**
 * Columna en guaraníes: "₲ 150.000".
 */
class MoneyColumn
{
    public static function make(string $name): TextColumn
    {
        return TextColumn::make($name)
            ->formatStateUsing(fn ($state) => $state === null ? null : Money::pyg((int) $state)->format())
            ->alignEnd();
    }
}
