<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Franja semanal en la que un profesor recibe reservas (ej. lunes de 15:00 a 20:00).
 */
#[Fillable(['organization_id', 'user_id', 'weekday', 'starts_at', 'ends_at'])]
class AvailabilitySlot extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }
}
