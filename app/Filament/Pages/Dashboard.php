<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\SetupGuide;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Escritorio: la guía "Primeros pasos" arriba (hasta completarla), los botones a lo principal
 * y las tarjetas de cada rol. Cada tarjeta se muestra según los permisos del usuario.
 */
class Dashboard extends BaseDashboard
{
    use SetupGuide;

    protected string $view = 'filament.pages.dashboard';

    /**
     * @return int|array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }
}
