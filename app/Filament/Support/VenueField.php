<?php

namespace App\Filament\Support;

use App\Models\Site;
use App\Models\Venue;
use App\Support\Scheduling\ScheduleConflicts;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

/**
 * Elegir la cancha (sala, aula) de un horario: "Polideportivo · Cancha 2". Se puede crear ahí
 * mismo, en un lugar que ya existe o en uno nuevo.
 */
class VenueField
{
    public static function make(string $name = 'venue_id'): Select
    {
        return Select::make($name)
            ->label(Terms::label('space', 'Cancha'))
            ->options(fn () => Venue::options())
            ->placeholder('Sin '.Terms::singular('space', 'cancha'))
            ->searchable()
            ->createOptionForm([
                Select::make('site_id')
                    ->label('Lugar')
                    ->options(fn () => Site::query()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('Lugar nuevo')
                    ->live(),
                TextInput::make('site_name')
                    ->label('Lugar nuevo')
                    ->placeholder('Polideportivo')
                    ->required(fn (Get $get) => blank($get('site_id')))
                    ->visible(fn (Get $get) => blank($get('site_id'))),
                TextInput::make('address')
                    ->label('Dirección')
                    ->visible(fn (Get $get) => blank($get('site_id'))),
                TextInput::make('name')
                    ->label(Terms::label('space', 'Cancha'))
                    ->placeholder(Terms::label('space', 'Cancha').' 1')
                    ->helperText(fn (Get $get) => blank($get('site_id')) ? 'Vacío si el lugar tiene una sola.' : null)
                    ->required(fn (Get $get) => filled($get('site_id')))
                    ->maxLength(100),
            ])
            ->createOptionUsing(function (array $data): int {
                $site = filled($data['site_id'] ?? null)
                    ? Site::query()->findOrFail($data['site_id'])
                    : Site::query()->firstOrCreate(['name' => trim($data['site_name'])], ['address' => $data['address'] ?? null]);

                return Venue::query()->firstOrCreate([
                    'site_id' => $site->id,
                    'name' => filled($data['name'] ?? null) ? trim($data['name']) : $site->name,
                ])->getKey();
            });
    }

    /**
     * Aviso de choques en vivo ("⚠ Sub-8: Choca con Sub-10 el martes…"). Recibe los horarios del
     * formulario ya armados.
     *
     * @param  callable(Get, mixed): list<array<string, mixed>>  $slots
     * @param  (callable(Get, mixed): list<string>)|null  $extra  otros avisos (ej. técnicos)
     */
    public static function conflicts(callable $slots, ?callable $extra = null): Text
    {
        $messages = function (Get $get, mixed $record = null) use ($slots, $extra): array {
            $items = $slots($get, $record);
            $byKey = ScheduleConflicts::forSlots($items);
            $names = collect($items)->mapWithKeys(fn (array $slot) => [(string) $slot['key'] => $slot['group_name']]);

            return collect($byKey)
                ->flatMap(fn (array $list, string $key) => array_map(fn (string $message) => "{$names[$key]}: {$message}", $list))
                ->merge($extra === null ? [] : $extra($get, $record))
                ->unique()->values()->all();
        };

        return Text::make(fn (Get $get, mixed $record = null) => new HtmlString(
            '<strong>Ojo, se superponen:</strong><br>'.collect($messages($get, $record))->map(fn (string $message) => e($message))->join('<br>')
                .'<br><span style="opacity:.75">Se puede guardar igual (por ejemplo, si comparten la cancha).</span>'
        ))
            ->color('warning')
            ->visible(fn (Get $get, mixed $record = null) => $messages($get, $record) !== []);
    }
}
