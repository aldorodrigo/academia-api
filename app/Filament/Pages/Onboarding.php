<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * La guía "Primeros pasos" vive en el Escritorio. Esta dirección queda para los links viejos.
 */
class Onboarding extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static ?string $slug = 'primeros-pasos';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $this->redirect(Dashboard::getUrl());
    }
}
