<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Mandatos: vencen y empiezan por fecha local de cada organización.
Schedule::command('roles:expire')->dailyAt('00:05')->timezone('America/Asuncion')->withoutOverlapping();
