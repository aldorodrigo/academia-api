<?php

namespace App\Actions\Billing;

use App\Models\Charge;
use Illuminate\Support\Facades\DB;

/**
 * Crea un cargo con sus ajustes (descuentos, becas) en una transacción.
 * El monto final nunca queda negativo.
 */
class IssueCharge
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $adjustments
     */
    public function handle(array $attributes, array $adjustments = []): Charge
    {
        return DB::transaction(function () use ($attributes, $adjustments) {
            $final = max(0, $attributes['base_amount'] + array_sum(array_column($adjustments, 'amount')));

            $charge = Charge::query()->create([...$attributes, 'final_amount' => $final]);

            foreach ($adjustments as $adjustment) {
                $charge->adjustments()->create([...$adjustment, 'organization_id' => $charge->organization_id]);
            }

            return $charge;
        });
    }
}
