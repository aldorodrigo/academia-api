<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cancha, sala o aula de un lugar (Polideportivo → Cancha 1, Cancha 2). Es donde se dan las
 * clases: los horarios y las clases apuntan acá.
 */
#[Fillable(['organization_id', 'site_id', 'name', 'address'])]
class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
    use BelongsToOrganization, HasFactory;

    /** El nombre que se muestra necesita el lugar. */
    protected $with = ['site'];

    protected static function booted(): void
    {
        // Una cancha creada sin lugar (datos viejos, tests) arma su lugar con el mismo nombre.
        static::creating(function (Venue $venue): void {
            if ($venue->site_id === null && $venue->organization_id !== null) {
                $venue->site_id = Site::query()->withoutGlobalScopes()->firstOrCreate(
                    ['organization_id' => $venue->organization_id, 'name' => $venue->name],
                    ['address' => $venue->address],
                )->id;
            }
        });
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * Para elegir en un select: id → "Polideportivo · Cancha 2", ordenadas por lugar.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()->get()->sortBy(fn (Venue $venue) => $venue->label)->pluck('label', 'id')->all();
    }

    /**
     * Cómo se muestra: "Polideportivo · Cancha 2"; si la cancha se llama como el lugar (un
     * lugar con una sola), solo "Polideportivo".
     *
     * @return Attribute<string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::get(function (): string {
            $site = $this->site;

            return $site === null || $site->name === $this->name ? $this->name : "{$site->name} · {$this->name}";
        });
    }
}
