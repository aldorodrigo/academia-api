<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Onboarding;
use Filament\Widgets\Widget;

/**
 * "Configurá tu club · 2 de 4" en el Escritorio, hasta completar la guía.
 */
class OnboardingProgress extends Widget
{
    protected string $view = 'filament.widgets.onboarding-progress';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Onboarding::canAccess() && ! Onboarding::checklist()['completed'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $checklist = Onboarding::checklist();
        $next = collect($checklist['steps'])->firstWhere('key', $checklist['next']);

        return [
            'done' => $checklist['done'],
            'total' => $checklist['total'],
            'next' => $next['title'] ?? null,
            'url' => Onboarding::getUrl(),
        ];
    }
}
