<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'name', 'starts_on', 'ends_on', 'is_current'])]
class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use BelongsToOrganization, HasFactory;

    protected static function booted(): void
    {
        // Una sola temporada actual por organización.
        static::saved(function (Season $season): void {
            if ($season->is_current) {
                static::query()->withoutGlobalScopes()
                    ->where('organization_id', $season->organization_id)
                    ->whereKeyNot($season->getKey())
                    ->where('is_current', true)
                    ->update(['is_current' => false]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->where('is_current', true);
    }

    /**
     * Temporada actual de la organización activa.
     */
    public static function currentOrNull(): ?self
    {
        return static::query()->current()->first();
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
