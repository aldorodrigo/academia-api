<?php

namespace App\Actions\Billing;

use App\Enums\DailyBasis;
use App\Enums\FeeFrequency;
use Carbon\CarbonImmutable;

/**
 * Período que cubre una cuota: mes, quincena, semana o día (o varios días agrupados).
 * `quantity` es la cantidad de días a cobrar en el cobro por día; null en los demás.
 */
final readonly class BillingPeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $dueOn,
        public ?int $quantity = null,
    ) {}

    public function key(): string
    {
        return $this->start->toDateString();
    }

    public function contains(CarbonImmutable $date): bool
    {
        return $date->startOfDay()->betweenIncluded($this->start, $this->end);
    }

    /**
     * Vencimiento para quien se inscribe ese día: con el período empezado tiene los mismos días para
     * pagar desde que se inscribe (`due_days`), nunca antes del vencimiento del período.
     */
    public function dueOnFor(CarbonImmutable $enrolledOn, int $dueDays): CarbonImmutable
    {
        return $this->contains($enrolledOn) ? $this->dueOn->max($enrolledOn->startOfDay()->addDays($dueDays)) : $this->dueOn;
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /**
     * "Cuota octubre 2026", "Cuota 1.ª quincena oct. 2026", "Cuota semana 12–18 oct.",
     * "Cuota 14/10 (1 entrenamiento)", "Cuota octubre 2026 (12 entrenamientos)".
     */
    public function description(FeeFrequency $frequency, ?DailyBasis $basis = null, bool $wholeMonth = false): string
    {
        return 'Cuota '.$this->label($frequency, $basis, $wholeMonth);
    }

    /**
     * Lo que cubre la cuota, sin "Cuota": "octubre 2026", "1.ª quincena oct 2026", "semana 12–18 oct",
     * "14/10 (1 entrenamiento)" (los ejemplos del asistente lo muestran igual que la familia).
     */
    public function label(FeeFrequency $frequency, ?DailyBasis $basis = null, bool $wholeMonth = false): string
    {
        // "ene." → "ene": más limpio en la descripción de la cuota.
        $es = fn (CarbonImmutable $date, string $format) => str_replace('.', '', $date->locale('es')->translatedFormat($format));
        $range = $this->start->month === $this->end->month
            ? $this->start->day.'–'.$es($this->end, 'j M')
            : $es($this->start, 'j M').' – '.$es($this->end, 'j M');

        $label = match (true) {
            $frequency === FeeFrequency::Monthly || $wholeMonth => $es($this->start, 'F Y'),
            $frequency === FeeFrequency::Fortnightly => ($this->start->day <= 15 ? '1.ª' : '2.ª').' quincena '.$es($this->start, 'M Y'),
            $this->days() === 1 => $this->start->format('d/m'),
            default => "semana {$range}",
        };

        $quantity = $this->quantity !== null && $basis !== null ? ' ('.$basis->quantityLabel($this->quantity).')' : '';

        return "{$label}{$quantity}";
    }
}
