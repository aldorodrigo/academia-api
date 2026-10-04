<?php

namespace App\Filament\Widgets\Dashboard;

/**
 * Cumpleaños de la semana (alumnos activos), para quien lleva a los alumnos (secretaría).
 */
class Birthdays extends Card
{
    protected string $view = 'filament.widgets.dashboard.birthdays';

    protected static ?int $sort = 7;

    public static function canView(): bool
    {
        return self::allows('Update:Student');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $today = $this->organization()->today();

        return [
            'birthdays' => collect($this->metrics()->birthdays())->map(fn (array $birthday) => [
                ...$birthday,
                'when' => match ((int) $birthday['date']->diffInDays($today, true)) {
                    0 => 'Hoy',
                    1 => 'Mañana',
                    default => ucfirst($birthday['date']->locale('es')->translatedFormat('l j')),
                },
            ]),
        ];
    }
}
