<?php

namespace App\Actions\Billing;

use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Vencimientos según billing.due_day (ajustado al último día en meses cortos).
 */
class DueDate
{
    /**
     * Vencimiento de la cuota del mes: día due_day del período.
     */
    public static function forPeriod(Organization $organization, CarbonImmutable $period): CarbonImmutable
    {
        $day = min((int) $organization->billing('due_day'), $period->daysInMonth);

        return $period->startOfMonth()->setDay($day);
    }

    /**
     * Próximo vencimiento desde una fecha (cargos sueltos, como la inscripción).
     */
    public static function next(Organization $organization, CarbonImmutable $from): CarbonImmutable
    {
        $thisMonth = self::forPeriod($organization, $from->startOfMonth());

        return $thisMonth->gte($from->startOfDay())
            ? $thisMonth
            : self::forPeriod($organization, $from->startOfMonth()->addMonth());
    }
}
