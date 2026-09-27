<?php

namespace App\Actions\Billing;

use App\Enums\DiscountType;
use App\Models\Charge;
use App\Models\DiscountRule;
use Carbon\CarbonInterface;

/**
 * Pronto pago: si el cargo mensual se salda hasta el día `until_day` de su mes,
 * se descuenta según la regla vigente. Se registra en la imputación del pago.
 */
class EarlyPaymentDiscount
{
    /**
     * @return array{amount: int, label: string}|null
     */
    public function for(Charge $charge, CarbonInterface $paidOn, int $pending): ?array
    {
        if ($charge->period === null || $pending <= 0) {
            return null;
        }

        $rule = DiscountRule::query()->withoutGlobalScopes()
            ->where('organization_id', $charge->organization_id)
            ->where('type', DiscountType::EarlyPayment)
            ->whereNotNull('until_day')
            ->whereHas('feeConcepts', fn ($query) => $query->whereKey($charge->fee_concept_id))
            ->get()
            ->first(function (DiscountRule $rule) use ($charge, $paidOn) {
                $limit = $charge->period->copy()->setDay(min($rule->until_day, $charge->period->daysInMonth));

                return $rule->appliesOn($paidOn) && $paidOn->startOfDay()->lte($limit);
            });

        if ($rule === null) {
            return null;
        }

        $amount = $rule->discountOn($pending);

        return $amount > 0 ? ['amount' => $amount, 'label' => $rule->adjustmentLabel()] : null;
    }
}
