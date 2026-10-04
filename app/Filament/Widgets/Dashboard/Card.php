<?php

namespace App\Filament\Widgets\Dashboard;

use App\Models\Organization;
use App\Support\Dashboard\Metrics;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Tarjeta del Escritorio. Cada una decide si se muestra según los permisos del usuario.
 */
abstract class Card extends Widget
{
    protected function organization(): Organization
    {
        return Filament::getTenant();
    }

    protected function metrics(): Metrics
    {
        return Metrics::for($this->organization());
    }

    public function money(int $amount): string
    {
        return Money::pyg($amount)->format();
    }

    protected static function allows(string $ability, ?string $model = null): bool
    {
        $user = auth()->user();

        return $user !== null && Filament::getTenant() instanceof Organization
            && ($model === null ? $user->can($ability) : $user->can($ability, $model));
    }
}
