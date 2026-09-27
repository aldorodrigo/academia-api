<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Carbon\CarbonInterface;
use Database\Factories\DiscountRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Regla de descuento: hermanos (por posición), convenio u otro (para jugadores
 * puntuales). Porcentaje o monto fijo, sobre ciertos conceptos, con vigencia.
 */
#[Fillable(['organization_id', 'name', 'type', 'percent', 'fixed_amount', 'sibling_position', 'until_day', 'valid_from', 'valid_to'])]
class DiscountRule extends Model
{
    /** @use HasFactory<DiscountRuleFactory> */
    use BelongsToOrganization, HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'percent' => 'integer',
            'fixed_amount' => 'integer',
            'sibling_position' => 'integer',
            'until_day' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'type', 'percent', 'fixed_amount', 'sibling_position', 'valid_from', 'valid_to'])
            ->logOnlyDirty()
            ->useLogName('billing');
    }

    public function appliesOn(CarbonInterface $date): bool
    {
        return $this->valid_from->lte($date) && ($this->valid_to === null || $this->valid_to->gte($date));
    }

    /**
     * Descuento sobre el monto que queda (redondeado al guaraní, nunca más que el monto).
     */
    public function discountOn(int $amount): int
    {
        $discount = $this->percent !== null
            ? Money::pyg($amount)->percentage($this->percent)->amount
            : (int) $this->fixed_amount;

        return min($discount, $amount);
    }

    /**
     * "Hermanos (2º hijo) −20 %", "Convenio Itaú −₲ 20.000".
     */
    public function adjustmentLabel(): string
    {
        $name = match ($this->type) {
            DiscountType::Siblings => 'Hermanos ('.$this->sibling_position.'º hijo'.($this->sibling_position >= 3 ? ' o más' : '').')',
            DiscountType::EarlyPayment => 'Pronto pago',
            default => $this->name,
        };

        return $this->percent !== null
            ? "{$name} −{$this->percent} %"
            : "{$name} −".Money::pyg((int) $this->fixed_amount)->format();
    }

    /**
     * @return BelongsToMany<FeeConcept, $this>
     */
    public function feeConcepts(): BelongsToMany
    {
        return $this->belongsToMany(FeeConcept::class);
    }

    /**
     * Jugadores a los que aplica (convenio y otro).
     *
     * @return BelongsToMany<Student, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class);
    }
}
