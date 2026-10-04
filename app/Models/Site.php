<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lugar donde entrena el club (Polideportivo, Club Jakare), con su dirección. Tiene una o
 * varias canchas, salas o aulas (`Venue`); el nombre de esas sale de term('space').
 */
#[Fillable(['organization_id', 'name', 'address'])]
class Site extends Model
{
    use BelongsToOrganization;

    /**
     * @return HasMany<Venue, $this>
     */
    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class)->orderBy('name');
    }
}
