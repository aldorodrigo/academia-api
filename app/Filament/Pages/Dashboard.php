<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Escritorio. Mientras la guía "Primeros pasos" esté incompleta y no se haya cerrado,
 * el administrador entra directo a la guía.
 */
class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        if (Onboarding::shouldOpen()) {
            $this->redirect(Onboarding::getUrl());
        }
    }
}
