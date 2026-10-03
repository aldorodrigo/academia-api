<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Qué se cobra de la cuota en curso (mes, quincena, semana) cuando alguien se inscribe cuando ya empezó.
 */
enum MidPeriod: string implements HasLabel
{
    /** La parte que falta (en el cobro por día, los días que faltan). */
    case Prorated = 'proporcional';

    case Full = 'completo';

    /** El período en curso no se cobra. */
    case Next = 'proximo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Prorated => 'Proporcional (lo que falta)',
            self::Full => 'Completo',
            self::Next => 'Desde la próxima cuota',
        };
    }

    /**
     * Con la unidad del plan: "El mes completo", "Lo que falta de la quincena (proporcional)",
     * "Desde la semana que viene".
     */
    public function labelFor(?BillingUnit $unit): string
    {
        if ($unit === null) {
            return $this->getLabel();
        }

        return match ($this) {
            self::Full => ucfirst($unit->whole()),
            self::Prorated => ucfirst($unit->remainder()).' (proporcional)',
            self::Next => 'Desde '.$unit->next(),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function optionsFor(?BillingUnit $unit): array
    {
        return collect([self::Full, self::Prorated, self::Next])
            ->mapWithKeys(fn (self $mode) => [$mode->value => $mode->labelFor($unit)])
            ->all();
    }
}
