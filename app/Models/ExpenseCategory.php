<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Categoría de gasto (alquiler de cancha, árbitros…). Agrupa el balance.
 */
#[Fillable(['organization_id', 'name'])]
class ExpenseCategory extends Model
{
    use BelongsToOrganization;

    public const DEFAULTS = ['Alquiler de cancha', 'Árbitros', 'Materiales', 'Transporte', 'Indumentaria', 'Otros'];
}
