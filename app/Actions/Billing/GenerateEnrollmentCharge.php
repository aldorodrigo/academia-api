<?php

namespace App\Actions\Billing;

use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\FeeConcept;
use App\Models\Tariff;
use Carbon\CarbonImmutable;

/**
 * Cargo de inscripción al inscribir a un jugador, si hay tarifa de "Inscripción"
 * para su categoría y temporada. Una sola vez por inscripción.
 */
class GenerateEnrollmentCharge
{
    public function __construct(
        private DiscountCalculator $discounts,
        private IssueCharge $issue,
        private ApplyCredit $applyCredit,
    ) {}

    public function handle(Enrollment $enrollment): ?Charge
    {
        $organization = $enrollment->organization;
        $concept = FeeConcept::enrollmentFee($organization);
        $season = $enrollment->season;

        if ($concept === null || $season === null) {
            return null;
        }

        $today = $organization->today();
        $on = CarbonImmutable::parse($season->starts_on)->max($today);
        $tariff = Tariff::applicable($concept, $season, $enrollment->group, $on);

        $key = "enr:{$enrollment->id}:con:{$concept->id}:per:once";

        if ($tariff === null || Charge::query()->withoutGlobalScopes()->where('unique_key', $key)->exists()) {
            return null;
        }

        $discounts = $this->discounts->calculate($enrollment, $concept, $on, $tariff->amount);

        $charge = $this->issue->handle([
            'organization_id' => $organization->id,
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'group_id' => $enrollment->group_id,
            'season_id' => $season->id,
            'fee_concept_id' => $concept->id,
            'tariff_id' => $tariff->id,
            'description' => "{$concept->name} {$season->name}",
            'base_amount' => $tariff->amount,
            'issued_on' => $today->toDateString(),
            'due_on' => DueDate::next($organization, $on)->toDateString(),
            'unique_key' => $key,
            'created_by' => auth()->id(),
        ], $discounts['adjustments']);

        // El saldo a favor de la familia se aplica solo al nuevo cargo.
        if ($enrollment->student->family !== null) {
            $this->applyCredit->forFamily($enrollment->student->family);
        }

        return $charge;
    }
}
