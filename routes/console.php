<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Mandatos: vencen y empiezan por fecha local de cada organización.
Schedule::command('roles:expire')->dailyAt('00:05')->timezone('America/Asuncion')->withoutOverlapping();

// Cuotas de las temporadas vigentes (mensual, quincenal, semanal o por día): todos los días,
// en la fecha local. Idempotente (lock + clave única por inscripción y período).
Schedule::command('charges:generate')->dailyAt('00:30')->timezone('America/Asuncion')->withoutOverlapping();

// Gastos recurrentes (alquiler de cancha…): pendientes del mes, el día 1. Idempotente.
Schedule::command('expenses:generate')->monthlyOn(1, '00:40')->timezone('America/Asuncion')->withoutOverlapping();
