<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avisos de días de clase de un usuario en una organización: cuántos y cuándo.
 * Offsets: minutos antes de la clase, o "eve" (el día anterior a las 20:00).
 */
#[Fillable(['organization_id', 'user_id', 'instructor_enabled', 'instructor_offsets', 'guardian_offsets'])]
class NotificationSetting extends Model
{
    use BelongsToOrganization;

    public const EVE = 'eve';

    public const MAX = 3;

    /** Opciones que se pueden elegir, en orden. */
    public const OPTIONS = [
        self::EVE => 'El día anterior a las 20:00',
        360 => '6 h antes',
        180 => '3 h antes',
        120 => '2 h antes',
        60 => '1 h antes',
        30 => '30 min antes',
    ];

    protected $attributes = ['instructor_enabled' => true];

    protected function casts(): array
    {
        return [
            'instructor_enabled' => 'boolean',
            'instructor_offsets' => 'array',
            'guardian_offsets' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function for(User $user, Organization $organization): self
    {
        return static::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->first()
            ?? new static(['organization_id' => $organization->id, 'user_id' => $user->id]);
    }

    /**
     * Avisos del técnico (los del club si no eligió).
     *
     * @return list<int|string>
     */
    public function instructorOffsets(Organization $organization): array
    {
        return self::normalize($this->instructor_offsets ?? [$organization->instructor_reminder_hours * 60]);
    }

    /**
     * Avisos del tutor (los del club si no eligió).
     *
     * @return list<int|string>
     */
    public function guardianOffsets(Organization $organization): array
    {
        return self::normalize($this->guardian_offsets ?? [$organization->class_reminder_hours * 60]);
    }

    /**
     * Sin repetidos, "eve" primero y después del más lejano al más cercano.
     *
     * @param  array<int|string>  $offsets
     * @return list<int|string>
     */
    public static function normalize(array $offsets): array
    {
        $values = collect($offsets)
            ->map(fn ($offset) => $offset === self::EVE ? self::EVE : (int) $offset)
            ->unique()
            ->values();

        return $values->sortBy(fn ($offset) => $offset === self::EVE ? PHP_INT_MIN : -$offset)->values()->all();
    }

    /**
     * Valores válidos (para validar el PUT).
     *
     * @return list<string>
     */
    public static function allowedValues(): array
    {
        return array_map('strval', array_keys(self::OPTIONS));
    }
}
