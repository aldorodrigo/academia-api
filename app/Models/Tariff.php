<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\TariffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarifa = concepto + temporada + categoría (o todas) + monto + vigencia.
 * No se edita: un cambio de monto es una tarifa nueva con otra vigencia, y no
 * altera los cargos ya emitidos.
 */
#[Fillable(['organization_id', 'fee_concept_id', 'season_id', 'group_id', 'amount', 'valid_from', 'created_by'])]
class Tariff extends Model
{
    /** @use HasFactory<TariffFactory> */
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'valid_from' => 'date',
        ];
    }

    /**
     * La que rige en la fecha: la de la categoría gana sobre la general y, entre
     * ellas, la de vigencia más reciente que no pase la fecha.
     */
    public static function applicable(FeeConcept $concept, Season $season, ?Group $group, CarbonInterface $on): ?self
    {
        return static::query()
            ->where('fee_concept_id', $concept->id)
            ->where('season_id', $season->id)
            ->where(fn ($query) => $query->whereNull('group_id')->when($group, fn ($query) => $query->orWhere('group_id', $group->id)))
            ->whereDate('valid_from', '<=', $on->toDateString())
            ->orderByRaw('group_id is null')
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return BelongsTo<FeeConcept, $this>
     */
    public function feeConcept(): BelongsTo
    {
        return $this->belongsTo(FeeConcept::class);
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
