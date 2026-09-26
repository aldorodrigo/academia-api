<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horario semanal de un grupo. weekday ISO: 1 = lunes … 7 = domingo.
 */
#[Fillable(['organization_id', 'group_id', 'weekday', 'starts_at', 'ends_at', 'venue_id'])]
class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use BelongsToOrganization, HasFactory;

    public const WEEKDAYS = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    protected static function booted(): void
    {
        // Los horarios se crean desde el grupo (repeater del panel): heredan su organización.
        static::creating(function (Schedule $schedule): void {
            $schedule->organization_id ??= $schedule->group?->organization_id;
        });
    }

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * "17:00" sin segundos.
     */
    public static function time(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 5);
    }
}
