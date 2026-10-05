<?php

namespace App\Actions\Academic;

use App\Models\Group;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Site;
use App\Models\Venue;
use App\Support\Onboarding\StepDrafts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Categorías con sus horarios, desde la guía: alta en lote y edición de una.
 */
class SaveGroups
{
    /**
     * @param  list<array{name: string, min_age?: ?int, max_age?: ?int, level?: ?string, capacity?: ?int, schedules?: list<array{weekday: int, starts_at: string, ends_at: string}>}>  $groups
     * @param  array{id?: ?int, name?: ?string, address?: ?string}|null  $venue  va en todos los horarios
     * @return Collection<int, Group>
     */
    public function create(Program $program, array $groups, ?array $venue = null): Collection
    {
        $existing = $program->groups()->pluck('name')->map(fn (string $name) => mb_strtolower($name));

        foreach ($groups as $index => $group) {
            if ($existing->contains(mb_strtolower(trim($group['name'])))) {
                throw ValidationException::withMessages([
                    "groups.{$index}.name" => "Ya existe «{$group['name']}» en {$program->name}.",
                ]);
            }
        }

        $created = DB::transaction(function () use ($program, $groups, $venue) {
            $venueId = $this->venueId($venue);

            return collect($groups)->map(function (array $data) use ($program, $venueId) {
                $group = Group::query()->create([
                    'program_id' => $program->id,
                    'name' => trim($data['name']),
                    'min_age' => $data['min_age'] ?? null,
                    'max_age' => $data['max_age'] ?? null,
                    'level' => $data['level'] ?? null,
                    'capacity' => $data['capacity'] ?? null,
                    'is_active' => true,
                ]);

                $this->replaceSchedules($group, $data['schedules'] ?? [], $venueId);

                return $group;
            });
        });

        // Lo que estaba a medio armar en la guía ya está creado.
        $organization = Organization::query()->find($program->organization_id);

        if ($organization !== null) {
            StepDrafts::forget($organization, 'groups');
        }

        return $created;
    }

    /**
     * @param  array{name: string, min_age?: ?int, max_age?: ?int, level?: ?string, capacity?: ?int, is_active?: bool, schedules?: list<array{weekday: int, starts_at: string, ends_at: string, venue_id?: ?int}>}  $data
     */
    public function update(Group $group, array $data): Group
    {
        $duplicate = Group::query()
            ->where('program_id', $group->program_id)
            ->whereKeyNot($group->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => "Ya existe «{$data['name']}» en {$group->program->name}."]);
        }

        return DB::transaction(function () use ($group, $data) {
            $group->update([
                'name' => trim($data['name']),
                'min_age' => $data['min_age'] ?? null,
                'max_age' => $data['max_age'] ?? null,
                'level' => $data['level'] ?? null,
                'capacity' => $data['capacity'] ?? null,
                'is_active' => $data['is_active'] ?? $group->is_active,
            ]);

            $this->replaceSchedules($group, $data['schedules'] ?? []);

            return $group;
        });
    }

    /**
     * @param  list<array{weekday: int, starts_at: string, ends_at: string, venue_id?: ?int}>  $schedules
     */
    private function replaceSchedules(Group $group, array $schedules, ?int $venueId = null): void
    {
        $group->schedules()->delete();

        foreach ($schedules as $schedule) {
            $group->schedules()->create([
                'organization_id' => $group->organization_id,
                'weekday' => (int) $schedule['weekday'],
                'starts_at' => $schedule['starts_at'],
                'ends_at' => $schedule['ends_at'],
                'venue_id' => $schedule['venue_id'] ?? $venueId,
            ]);
        }
    }

    /**
     * @param  array{id?: ?int, name?: ?string, address?: ?string}|null  $venue
     */
    private function venueId(?array $venue): ?int
    {
        if (filled($venue['id'] ?? null)) {
            return Venue::query()->findOrFail($venue['id'])->id;
        }

        if (blank($venue['name'] ?? null)) {
            return null;
        }

        // Lugar nuevo (o el que ya existe con ese nombre) con su cancha del mismo nombre.
        $site = Site::query()->firstOrCreate(
            ['name' => trim($venue['name'])],
            ['address' => filled($venue['address'] ?? null) ? trim($venue['address']) : null],
        );

        return $site->venues()->first()?->id
            ?? Venue::query()->create(['site_id' => $site->id, 'name' => $site->name])->id;
    }
}
