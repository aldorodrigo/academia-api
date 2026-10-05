<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasLabel;

/**
 * Duración de la temporada: calcula la fecha de fin y el nombre sugerido.
 */
enum SeasonKind: string implements HasLabel
{
    case Annual = 'anual';
    case Semester = 'semestral';
    case Monthly = 'mensual';
    case Fortnightly = 'quincenal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Annual => 'Anual',
            self::Semester => 'Semestral',
            self::Monthly => 'Mensual',
            self::Fortnightly => 'Quincenal',
        };
    }

    public function endsOn(CarbonImmutable $startsOn): CarbonImmutable
    {
        $next = match ($this) {
            self::Annual => $startsOn->addYearNoOverflow(),
            self::Semester => $startsOn->addMonthsNoOverflow(6),
            self::Monthly => $startsOn->addMonthNoOverflow(),
            self::Fortnightly => $startsOn->addDays(15),
        };

        return $next->subDay();
    }

    /**
     * "2027", "1.er semestre 2027", "Enero 2027", "1.ª quincena de enero 2027".
     */
    public function suggestedName(CarbonImmutable $startsOn): string
    {
        $month = $startsOn->locale('es')->translatedFormat('F');

        return match ($this) {
            // "Temporada 2026" (la app y el panel no le anteponen "Temporada" si ya lo dice).
            self::Annual => 'Temporada '.($startsOn->month === 1 ? (string) $startsOn->year : $startsOn->year.'/'.($startsOn->year + 1)),
            self::Semester => ($startsOn->month <= 6 ? '1.er' : '2.º')." semestre {$startsOn->year}",
            self::Monthly => ucfirst($month)." {$startsOn->year}",
            self::Fortnightly => ($startsOn->day <= 15 ? '1.ª' : '2.ª')." quincena de {$month} {$startsOn->year}",
        };
    }

    /**
     * Ejemplo para la tarjeta del asistente ("1 ene – 31 dic").
     */
    public function example(CarbonImmutable $startsOn): string
    {
        $format = fn (CarbonImmutable $date) => $date->locale('es')->translatedFormat('j M');

        return $format($startsOn).' – '.$format($this->endsOn($startsOn));
    }

    /**
     * Frecuencia de cobro que se sugiere para esta duración.
     */
    public function defaultFrequency(): FeeFrequency
    {
        return match ($this) {
            self::Annual, self::Semester, self::Monthly => FeeFrequency::Monthly,
            self::Fortnightly => FeeFrequency::Weekly,
        };
    }
}
