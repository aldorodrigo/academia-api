<?php

namespace App\Actions\Billing;

use App\Enums\AdjustmentType;
use App\Enums\DiscountType;
use App\Models\DiscountRule;
use App\Models\Enrollment;
use App\Models\FeeConcept;
use App\Models\Scholarship;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Descuentos y becas de un cargo, en el orden configurado por la organización
 * (billing.discount_order). Cada uno se calcula sobre lo que queda del anterior,
 * redondeado al guaraní; el cargo nunca queda negativo.
 */
class DiscountCalculator
{
    /**
     * @return array{final: int, adjustments: list<array{type: AdjustmentType, label: string, amount: int, discount_rule_id: ?int, scholarship_id: ?int}>}
     */
    public function calculate(Enrollment $enrollment, FeeConcept $concept, CarbonInterface $on, int $base): array
    {
        $remaining = $base;
        $adjustments = [];

        foreach ($enrollment->organization->billing('discount_order') as $type) {
            foreach ($this->discountsOf(AdjustmentType::from($type), $enrollment, $concept, $on) as $discount) {
                $amount = $discount instanceof Scholarship
                    ? min((int) round($remaining * $discount->percent / 100), $remaining)
                    : $discount->discountOn($remaining);

                if ($amount <= 0) {
                    continue;
                }

                $remaining -= $amount;
                $adjustments[] = [
                    'type' => AdjustmentType::from($type),
                    'label' => $discount instanceof Scholarship ? "Beca {$discount->percent} %" : $discount->adjustmentLabel(),
                    'amount' => -$amount,
                    'discount_rule_id' => $discount instanceof DiscountRule ? $discount->id : null,
                    'scholarship_id' => $discount instanceof Scholarship ? $discount->id : null,
                ];
            }
        }

        return ['final' => $remaining, 'adjustments' => $adjustments];
    }

    /**
     * Beca aprobada y vigente de la inscripción (solo sobre la cuota mensual).
     */
    public function scholarshipFor(Enrollment $enrollment, CarbonInterface $on): ?Scholarship
    {
        return $enrollment->scholarships()
            ->withoutGlobalScopes()
            ->get()
            ->filter(fn (Scholarship $scholarship) => $scholarship->appliesOn($on))
            ->sortByDesc('percent')
            ->first();
    }

    /**
     * Posición del hijo entre los hermanos de la familia con inscripción facturable
     * (el mayor es el 1º). Cada hijo cuenta una vez.
     */
    public function siblingPosition(Student $student): int
    {
        if ($student->family_id === null) {
            return 1;
        }

        $siblings = Student::query()->withoutGlobalScopes()
            ->where('family_id', $student->family_id)
            ->whereHas('enrollments', fn ($query) => $query->withoutGlobalScopes()->billable())
            ->orderBy('birth_date')
            ->orderBy('id')
            ->pluck('id');

        $index = $siblings->search($student->id);

        return $index === false ? 1 : $index + 1;
    }

    /**
     * @return Collection<int, DiscountRule|Scholarship>
     */
    private function discountsOf(AdjustmentType $type, Enrollment $enrollment, FeeConcept $concept, CarbonInterface $on): Collection
    {
        if ($type === AdjustmentType::Scholarship) {
            return collect($concept->isMonthlyFee() ? [$this->scholarshipFor($enrollment, $on)] : [])->filter();
        }

        $rules = DiscountRule::query()->withoutGlobalScopes()
            ->where('organization_id', $enrollment->organization_id)
            ->where('type', $type->value)
            ->whereHas('feeConcepts', fn ($query) => $query->whereKey($concept->id))
            ->get()
            ->filter(fn (DiscountRule $rule) => $rule->appliesOn($on));

        return match (DiscountType::from($type->value)) {
            // La regla de la mayor posición que alcanza (la de 3 vale del 3º en adelante).
            DiscountType::Siblings => $rules
                ->filter(fn (DiscountRule $rule) => $rule->sibling_position <= $this->siblingPosition($enrollment->student))
                ->sortByDesc('sibling_position')
                ->take(1)
                ->values(),
            default => $rules
                ->filter(fn (DiscountRule $rule) => $rule->students()->whereKey($enrollment->student_id)->exists())
                ->values(),
        };
    }
}
