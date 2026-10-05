<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Borrador de un paso de "Primeros pasos" (lo que se está armando). Uno vigente por paso; al crear lo
 * que pedía queda como usado (soft delete), no se borra.
 */
#[Fillable(['organization_id', 'step', 'draft', 'updated_by'])]
class OnboardingDraft extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected function casts(): array
    {
        return ['draft' => 'array'];
    }
}
