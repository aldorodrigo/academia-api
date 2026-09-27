<?php

namespace App\Actions\Attendance;

use App\Models\ClassSession;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Clases de un día a partir de los horarios de los grupos. Se crean al
 * consultarlas (idempotente por grupo + fecha + hora) y solo si el día cae
 * dentro de una temporada vigente de la disciplina del grupo.
 */
class ResolveClassSessions
{
    /** @var array<string, bool> */
    private array $seasonCache = [];

    /**
     * @param  iterable<Group>  $groups
     * @return Collection<int, ClassSession> por hora de inicio
     */
    public function forDate(iterable $groups, CarbonImmutable $date): Collection
    {
        $groups = (new EloquentCollection(collect($groups)->all()))->loadMissing('schedules');

        foreach ($groups as $group) {
            if (! $group->is_active || ! $this->inSeason($group, $date)) {
                continue;
            }

            $group->schedules
                ->filter(fn (Schedule $schedule) => $schedule->weekday === $date->dayOfWeekIso)
                ->each(fn (Schedule $schedule) => ClassSession::query()->createOrFirst(
                    ['group_id' => $group->id, 'date' => $date->toDateString(), 'starts_at' => $schedule->starts_at],
                    ['organization_id' => $group->organization_id, 'ends_at' => $schedule->ends_at, 'venue_id' => $schedule->venue_id],
                ));
        }

        // También las que ya existían aunque después haya cambiado el horario.
        return ClassSession::query()
            ->whereIn('group_id', $groups->pluck('id'))
            ->whereDate('date', $date->toDateString())
            ->with(['group.program', 'venue', 'organization'])
            ->orderBy('starts_at')
            ->get()
            ->toBase();
    }

    /**
     * Clases de los grupos entre dos fechas (inclusive).
     *
     * @param  iterable<Group>  $groups
     * @return Collection<int, ClassSession> por fecha y hora
     */
    public function between(iterable $groups, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $sessions = collect();

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $sessions = $sessions->merge($this->forDate($groups, $date));
        }

        return $sessions;
    }

    private function inSeason(Group $group, CarbonImmutable $date): bool
    {
        $key = "{$group->program_id}:{$date->toDateString()}";

        return $this->seasonCache[$key] ??= Season::query()->active($date)->forProgram($group->program_id)->exists();
    }
}
