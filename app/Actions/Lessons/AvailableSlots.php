<?php

namespace App\Actions\Lessons;

use App\Enums\BookingStatus;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\LessonProfile;
use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Horas libres de un profesor: sus franjas partidas por la duración de la clase, sin las
 * reservadas ni las que empiezan antes de la anticipación mínima. Fechas y horas locales.
 */
class AvailableSlots
{
    /**
     * @return list<array{date: string, times: list<string>}> solo días con alguna hora libre
     */
    public function for(LessonProfile $profile, Organization $organization, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone($organization->timezone);
        $today = $now->startOfDay();
        $from = $from->max($today);
        $to = $to->min($today->addDays($profile->days_ahead));
        $earliest = $now->addMinutes($profile->min_notice_minutes);

        if ($from->gt($to)) {
            return [];
        }

        $ranges = AvailabilitySlot::query()->where('user_id', $profile->user_id)->get()->groupBy('weekday');
        $taken = Booking::query()
            ->where('user_id', $profile->user_id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Attended, BookingStatus::Absent])
            ->get()
            ->map(fn (Booking $booking) => [$booking->date->toDateString(), self::minutes($booking->starts_at), self::minutes($booking->ends_at)]);

        $days = [];

        for ($day = $from; ! $day->gt($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $times = [];

            foreach ($ranges->get($day->dayOfWeekIso, collect()) as $range) {
                $end = self::minutes($range->ends_at);

                for ($start = self::minutes($range->starts_at); $start + $profile->duration_minutes <= $end; $start += $profile->duration_minutes) {
                    $finish = $start + $profile->duration_minutes;
                    $startsAt = $day->setTime(intdiv($start, 60), $start % 60);

                    $busy = $taken->contains(fn (array $booking) => $booking[0] === $date && $booking[1] < $finish && $start < $booking[2]);

                    if (! $busy && ! $startsAt->lt($earliest)) {
                        $times[] = self::time($start);
                    }
                }
            }

            if ($times !== []) {
                sort($times);
                $days[] = ['date' => $date, 'times' => array_values(array_unique($times))];
            }
        }

        return $days;
    }

    /**
     * La hora está libre ese día (para validar una reserva).
     */
    public function isFree(LessonProfile $profile, Organization $organization, CarbonImmutable $date, string $time, ?CarbonImmutable $now = null): bool
    {
        $day = collect($this->for($profile, $organization, $date, $date, $now))->first();

        return $day !== null && in_array(substr($time, 0, 5), $day['times'], true);
    }

    public static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    public static function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
