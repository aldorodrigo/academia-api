<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Sede o cancha (propia o alquilada).
 */
#[Fillable(['organization_id', 'name', 'address'])]
class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
    use BelongsToOrganization, HasFactory;
}
