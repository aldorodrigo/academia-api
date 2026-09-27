<?php

namespace App\Models;

use App\Enums\FeeConceptKind;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\FeeConceptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Concepto de cobro (cuota mensual, inscripción, torneo…).
 * Los del sistema se identifican por `code` y se crean con la organización.
 */
#[Fillable(['organization_id', 'name', 'kind', 'code'])]
class FeeConcept extends Model
{
    /** @use HasFactory<FeeConceptFactory> */
    use BelongsToOrganization, HasFactory;

    public const MONTHLY_FEE = 'monthly_fee';

    public const ENROLLMENT_FEE = 'enrollment_fee';

    protected $attributes = ['kind' => 'one_time'];

    protected function casts(): array
    {
        return ['kind' => FeeConceptKind::class];
    }

    public static function monthlyFee(Organization $organization): ?self
    {
        return static::system($organization, self::MONTHLY_FEE);
    }

    public static function enrollmentFee(Organization $organization): ?self
    {
        return static::system($organization, self::ENROLLMENT_FEE);
    }

    private static function system(Organization $organization, string $code): ?self
    {
        return static::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', $code)
            ->first();
    }

    public function isMonthlyFee(): bool
    {
        return $this->code === self::MONTHLY_FEE;
    }

    /**
     * @return HasMany<Tariff, $this>
     */
    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class);
    }
}
