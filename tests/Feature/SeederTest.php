<?php

use App\Models\Charge;
use App\Models\Season;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Mail;

it('el seeder de desarrollo crea la temporada anual y la colonia, y es idempotente', function () {
    $this->travelTo('2026-09-27 12:00:00');
    Mail::fake();

    $this->seed(DatabaseSeeder::class);
    $charges = Charge::query()->withoutGlobalScopes()->count();
    $this->seed(DatabaseSeeder::class);

    $anual = Season::query()->withoutGlobalScopes()->where('name', '2026')->sole();
    $colonia = Season::query()->withoutGlobalScopes()->where('name', 'Colonia de verano 2027')->with('programs')->sole();
    $campCharges = Charge::query()->withoutGlobalScopes()->where('season_id', $colonia->id)->orderBy('period_start')->get();

    expect(Charge::query()->withoutGlobalScopes()->count())->toBe($charges)
        ->and($anual->fee_frequency->value)->toBe('mensual')
        ->and($colonia->programs->pluck('name')->sort()->values()->all())->toBe(['Fútbol', 'Pádel'])
        // Mateo (Sub-10, lunes y miércoles): dos semanas de 2 entrenamientos × ₲ 20.000.
        ->and($campCharges->map(fn (Charge $c) => [$c->quantity, $c->base_amount])->all())->toBe([[2, 40000], [2, 40000]]);
});
