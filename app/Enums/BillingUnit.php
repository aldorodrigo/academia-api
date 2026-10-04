<?php

namespace App\Enums;

/**
 * Lo que cubre cada cuota según el plan de cobro: mes, quincena, semana o día. Da las palabras
 * (con su género) para no decir "período" en el asistente, al inscribir y en la app.
 */
enum BillingUnit: string
{
    case Month = 'mes';
    case Fortnight = 'quincena';
    case Week = 'semana';
    case Day = 'dia';

    private const WEEKDAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    /**
     * La unidad del plan; en "por día", la de la agrupación. Null sin plan de cobro.
     */
    public static function for(FeeFrequency|string|null $frequency, DailyGrouping|string|null $grouping = null): ?self
    {
        $frequency = is_string($frequency) ? FeeFrequency::tryFrom($frequency) : $frequency;
        $grouping = is_string($grouping) ? DailyGrouping::tryFrom($grouping) : $grouping;

        return match ($frequency) {
            null => null,
            FeeFrequency::Monthly => self::Month,
            FeeFrequency::Fortnightly => self::Fortnight,
            FeeFrequency::Weekly => self::Week,
            FeeFrequency::Daily => match ($grouping ?? DailyGrouping::Month) {
                DailyGrouping::Month => self::Month,
                DailyGrouping::Week => self::Week,
                DailyGrouping::Day => self::Day,
            },
        };
    }

    public function noun(): string
    {
        return $this === self::Day ? 'día' : $this->value;
    }

    public function isFeminine(): bool
    {
        return in_array($this, [self::Fortnight, self::Week], true);
    }

    /** "el mes", "la semana". */
    private function the(): string
    {
        return ($this->isFeminine() ? 'la ' : 'el ').$this->noun();
    }

    /** "del mes", "de la semana". */
    private function ofThe(): string
    {
        return $this->isFeminine() ? 'de la '.$this->noun() : 'del '.$this->noun();
    }

    /** "cada mes", "cada quincena". */
    public function each(): string
    {
        return 'cada '.$this->noun();
    }

    /** "este mes", "esta semana". */
    public function current(): string
    {
        return ($this->isFeminine() ? 'esta ' : 'este ').$this->noun();
    }

    /** "el mes que viene", "la quincena que viene", "el día siguiente". */
    public function next(): string
    {
        return $this === self::Day ? 'el día siguiente' : $this->the().' que viene';
    }

    /** "del mes en curso", "de la semana en curso"; en el día, "del día". */
    public function ofCurrent(): string
    {
        return $this === self::Day ? 'del día' : $this->ofThe().' en curso';
    }

    /** "el mes completo", "la quincena completa". */
    public function whole(): string
    {
        return $this->the().($this->isFeminine() ? ' completa' : ' completo');
    }

    /** "lo que falta del mes", "lo que falta de la semana". */
    public function remainder(): string
    {
        return 'lo que falta '.$this->ofThe();
    }

    /** "a mitad de mes". */
    public function midway(): string
    {
        return 'a mitad de '.$this->noun();
    }

    /** "en el mes", "en la semana"; en el día, "ese día". */
    public function within(): string
    {
        return $this === self::Day ? 'ese día' : 'en '.$this->the();
    }

    /** Cuándo se crea cada cuota: "al empezar cada mes"; agrupado por día, "el mismo día de cada entrenamiento". */
    public function createdAtStart(): string
    {
        return $this === self::Day ? 'el mismo día de cada entrenamiento' : 'al empezar '.$this->each();
    }

    /** Por clase asistida o dictada: "al terminar cada mes"; agrupado por día, "unos días después de cada clase". */
    public function createdAfter(): string
    {
        return $this === self::Day ? 'unos días después de cada clase' : 'al terminar '.$this->each();
    }

    /**
     * Inscribirse a mitad de la unidad tiene sentido (en el día, no).
     */
    public function allowsMidway(): bool
    {
        return $this !== self::Day;
    }

    /**
     * Pregunta del vencimiento, como se piensa.
     */
    public function dueQuestion(): string
    {
        return match ($this) {
            self::Month => '¿Qué día del mes vence?',
            self::Fortnight => '¿Cuándo vence cada quincena?',
            self::Week => '¿Qué día de la semana vence?',
            self::Day => '¿Cuándo vence?',
        };
    }

    /**
     * Opciones del vencimiento (`due_days` → texto). Si el valor guardado se sale del rango, se agrega.
     *
     * @return array<int, string>
     */
    public function dueOptions(?int $current = null): array
    {
        $options = match ($this) {
            self::Month => collect(range(0, 27))->mapWithKeys(fn (int $days) => [$days => 'Día '.($days + 1)]),
            self::Fortnight => collect(range(0, 14))->mapWithKeys(fn (int $days) => [$days => $days === 0
                ? 'El día que empieza (el 1 y el 16)'
                : ($days === 1 ? 'Al día siguiente' : "A los {$days} días").' (el '.(1 + $days).' y el '.(16 + $days).')']),
            self::Week => collect(range(0, 6))->mapWithKeys(fn (int $days) => [$days => ucfirst(self::WEEKDAYS[$days])]),
            self::Day => collect(range(0, 7))->mapWithKeys(fn (int $days) => [$days => ucfirst($this->dueText($days))]),
        };

        if ($current !== null && ! $options->has($current)) {
            $options->put($current, ucfirst($this->dueText($current)));
        }

        return $options->sortKeys()->all();
    }

    /**
     * "el día 10 de cada mes", "a los 3 días de empezar cada quincena (el 4 y el 19)",
     * "el jueves de cada semana", "el mismo día".
     */
    public function dueText(int $dueDays): string
    {
        $fallback = "{$dueDays} días después de empezar ".$this->the();

        return match ($this) {
            self::Month => $dueDays <= 27 ? 'el día '.($dueDays + 1).' de cada mes' : $fallback,
            self::Fortnight => match (true) {
                $dueDays === 0 => 'el día que empieza cada quincena (el 1 y el 16)',
                $dueDays <= 14 => ($dueDays === 1 ? 'al día siguiente' : "a los {$dueDays} días")
                    .' de empezar cada quincena (el '.(1 + $dueDays).' y el '.(16 + $dueDays).')',
                default => $fallback,
            },
            self::Week => $dueDays <= 6 ? 'el '.self::WEEKDAYS[$dueDays].' de cada semana' : $fallback,
            self::Day => match ($dueDays) {
                0 => 'el mismo día',
                1 => 'al día siguiente',
                default => "a los {$dueDays} días",
            },
        };
    }
}
