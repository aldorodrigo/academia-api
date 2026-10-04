<?php

namespace App\Support\Scheduling;

use App\Enums\ClassStatus;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Collection;

/**
 * Choques de horarios: dos categorías en la misma cancha a la misma hora, o un técnico con
 * dos categorías a la vez. Solo avisa (a veces se comparte la cancha a propósito).
 */
class ScheduleConflicts
{
    private const WEEKDAYS = [1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    /**
     * Horarios que se están cargando contra los guardados y entre sí.
     *
     * @param  list<array{key?: string, group_id?: ?int, group_name: string, weekday: int, starts_at: string, ends_at: string, venue_id?: ?int}>  $slots
     * @return array<string, list<string>> clave del horario → avisos
     */
    public static function forSlots(array $slots): array
    {
        $slots = collect($slots)->values()->map(fn (array $slot, int $index) => [
            ...$slot,
            'key' => (string) ($slot['key'] ?? $index),
            'starts_at' => substr((string) $slot['starts_at'], 0, 5),
            'ends_at' => substr((string) $slot['ends_at'], 0, 5),
        ]);
        $editing = $slots->pluck('group_id')->filter()->unique()->all();
        $saved = self::saved($slots->pluck('venue_id')->filter()->unique()->all(), $editing);
        $venues = Venue::query()->whereKey($slots->pluck('venue_id')->filter()->unique())->get()->keyBy('id');
        $warnings = [];

        foreach ($slots as $i => $slot) {
            if (empty($slot['venue_id'])) {
                continue;
            }

            $venue = $venues[$slot['venue_id']]->label ?? '';

            foreach ($saved as $other) {
                if ($other->venue_id === (int) $slot['venue_id'] && self::overlaps($slot, $other->weekday, $other->starts_at, $other->ends_at)) {
                    $warnings[$slot['key']][] = self::message($other->group->name, $other->weekday, $other->starts_at, $other->ends_at, $venue);
                }
            }

            foreach ($slots as $j => $other) {
                if ($i !== $j && ($other['venue_id'] ?? null) == $slot['venue_id'] && $other['group_name'] !== $slot['group_name']
                    && self::overlaps($slot, (int) $other['weekday'], $other['starts_at'], $other['ends_at'])) {
                    $warnings[$slot['key']][] = self::message($other['group_name'], (int) $other['weekday'], $other['starts_at'], $other['ends_at'], $venue);
                }
            }
        }

        return array_map(fn (array $messages) => array_values(array_unique($messages)), $warnings);
    }

    /**
     * Un técnico con dos de sus categorías a la misma hora (en cualquier cancha).
     *
     * @param  list<int>  $groupIds  las categorías que va a tener
     * @return list<string>
     */
    public static function forInstructor(User $instructor, array $groupIds): array
    {
        $schedules = Schedule::query()->with('group')->whereIn('group_id', $groupIds)->get();
        $warnings = [];

        foreach ($schedules as $a) {
            foreach ($schedules as $b) {
                if ($a->group_id < $b->group_id && $a->weekday === $b->weekday
                    && self::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) {
                    $warnings[] = "{$instructor->name} tiene {$a->group->name} y {$b->group->name} el ".self::WEEKDAYS[$a->weekday]
                        .' a las '.Schedule::time(max($a->starts_at, $b->starts_at)).'.';
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Los técnicos de una categoría que quedarían con dos clases a la vez con estos horarios.
     *
     * @param  list<array{weekday: int, starts_at: string, ends_at: string}>  $schedules
     * @param  list<int>  $instructorIds
     * @return list<string>
     */
    public static function forGroupInstructors(?int $groupId, string $groupName, array $schedules, array $instructorIds): array
    {
        $warnings = [];

        foreach (User::query()->whereKey($instructorIds)->get() as $instructor) {
            $others = Schedule::query()->with('group')
                ->whereHas('group', fn ($query) => $query->where('is_active', true)
                    ->whereHas('instructors', fn ($users) => $users->whereKey($instructor->id)))
                ->when($groupId, fn ($query) => $query->where('group_id', '!=', $groupId))
                ->get();

            foreach ($schedules as $slot) {
                foreach ($others as $other) {
                    if ($other->weekday === (int) $slot['weekday'] && self::timesOverlap($slot['starts_at'], $slot['ends_at'], $other->starts_at, $other->ends_at)) {
                        $warnings[] = "{$instructor->name} ya tiene {$other->group->name} el ".self::WEEKDAYS[$other->weekday]
                            .' a las '.Schedule::time($other->starts_at).": no puede dar {$groupName} a la misma hora.";
                    }
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Una clase reprogramada (recuperación) contra lo que ya hay ese día en esa cancha: los horarios
     * de las otras categorías y las otras clases de ese día (recuperaciones incluidas).
     *
     * @return list<string>
     */
    public static function forClass(ClassSession $class): array
    {
        if ($class->venue_id === null) {
            return [];
        }

        $class->loadMissing('venue');
        $weekday = $class->date->dayOfWeekIso;
        $warnings = [];
        $message = fn (string $group, string $startsAt, string $endsAt) => "Ese día {$group} usa {$class->venue->label} de "
            .Schedule::time($startsAt).' a '.Schedule::time($endsAt).'.';

        $schedules = self::saved([$class->venue_id], [$class->group_id])->where('weekday', $weekday);
        foreach ($schedules as $other) {
            if (self::timesOverlap($class->starts_at, $class->ends_at, $other->starts_at, $other->ends_at)) {
                $warnings[] = $message($other->group->name, $other->starts_at, $other->ends_at);
            }
        }

        $sessions = ClassSession::query()->with('group')
            ->whereDate('date', $class->date)
            ->where('venue_id', $class->venue_id)
            ->where('group_id', '!=', $class->group_id)
            ->whereKeyNot($class->id)
            ->whereIn('status', [ClassStatus::Scheduled])
            ->get();
        foreach ($sessions as $other) {
            if (self::timesOverlap($class->starts_at, $class->ends_at, $other->starts_at, $other->ends_at)) {
                $warnings[] = $message($other->group->name, $other->starts_at, $other->ends_at);
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Horarios guardados de categorías activas en esas canchas (sin las que se están editando).
     *
     * @param  list<int>  $venueIds
     * @param  list<int>  $exceptGroups
     * @return Collection<int, Schedule>
     */
    private static function saved(array $venueIds, array $exceptGroups): Collection
    {
        if ($venueIds === []) {
            return collect();
        }

        return Schedule::query()
            ->with('group')
            ->whereIn('venue_id', $venueIds)
            ->whereNotIn('group_id', $exceptGroups)
            ->whereHas('group', fn ($query) => $query->where('is_active', true))
            ->get();
    }

    /**
     * @param  array{weekday: int|string, starts_at: string, ends_at: string}  $slot
     */
    private static function overlaps(array $slot, int $weekday, string $startsAt, string $endsAt): bool
    {
        return (int) $slot['weekday'] === $weekday && self::timesOverlap($slot['starts_at'], $slot['ends_at'], $startsAt, $endsAt);
    }

    private static function timesOverlap(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        [$aStart, $aEnd, $bStart, $bEnd] = array_map(fn (string $time) => substr($time, 0, 5), [$aStart, $aEnd, $bStart, $bEnd]);

        return $aStart < $bEnd && $bStart < $aEnd;
    }

    private static function message(string $group, int $weekday, string $startsAt, string $endsAt, string $venue): string
    {
        return "Choca con {$group} el ".self::WEEKDAYS[$weekday].' de '.Schedule::time($startsAt).' a '.Schedule::time($endsAt)
            .($venue === '' ? '' : " en {$venue}").'.';
    }
}
