<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Todo modelo de dominio pertenece a una organización.
 *
 * - Filtra automáticamente por la organización activa.
 * - Asigna organization_id al crear si no viene seteado.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (self $model): void {
            $current = app(CurrentOrganization::class);

            if (blank($model->organization_id) && $current->check()) {
                $model->organization_id = $current->id();
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
