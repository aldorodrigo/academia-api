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

// Aviso "¿Lo llevás?" unas horas antes de cada clase, a quienes lo pidieron. Idempotente.
Schedule::command('classes:remind')->everyFifteenMinutes()->withoutOverlapping();

// Paquetes de clases particulares: vencen al pasar su fecha y se avisa 7 días y 1 día antes. Idempotente.
Schedule::command('packs:expire')->dailyAt('08:00')->timezone('America/Asuncion')->withoutOverlapping();

// Cuentas que no ingresaron el código en 24 horas: se borran (no ocupan el número ni el correo).
Schedule::command('accounts:prune-unverified')->hourly()->withoutOverlapping();
