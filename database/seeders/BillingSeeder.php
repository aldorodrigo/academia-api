<?php

namespace Database\Seeders;

use App\Actions\Billing\GenerateMonthlyCharges;
use App\Enums\DiscountType;
use App\Enums\ScholarshipStatus;
use App\Models\DiscountRule;
use App\Models\Enrollment;
use App\Models\FeeConcept;
use App\Models\Scholarship;
use App\Models\Season;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Datos de desarrollo de cobros de la organización activa: tarifas, hermanos −20 %,
 * beca del 50 % para Sofía y cuotas desde el inicio de la temporada hasta el mes actual.
 * Idempotente.
 */
class BillingSeeder extends Seeder
{
    public function run(): void
    {
        $organization = app(CurrentOrganization::class)->get();
        $season = Season::currentOrNull();

        if ($season === null) {
            return;
        }

        $monthly = FeeConcept::monthlyFee($organization);

        Tariff::query()->firstOrCreate(
            ['fee_concept_id' => $monthly->id, 'season_id' => $season->id, 'group_id' => null, 'valid_from' => $season->starts_on],
            ['amount' => 150000],
        );
        Tariff::query()->firstOrCreate(
            ['fee_concept_id' => FeeConcept::enrollmentFee($organization)->id, 'season_id' => $season->id, 'group_id' => null, 'valid_from' => $season->starts_on],
            ['amount' => 100000],
        );

        DiscountRule::query()->firstOrCreate(
            ['type' => DiscountType::Siblings, 'sibling_position' => 2],
            ['name' => 'Hermanos', 'percent' => 20, 'valid_from' => $season->starts_on],
        )->feeConcepts()->syncWithoutDetaching([$monthly->id]);

        $sofia = Enrollment::query()->current()->whereHas('student', fn ($query) => $query->where('document', '7234567'))->first();

        if ($sofia !== null) {
            Scholarship::query()->firstOrCreate(
                ['enrollment_id' => $sofia->id],
                [
                    'student_id' => $sofia->student_id,
                    'percent' => 50,
                    'reason' => 'Situación económica de la familia',
                    'valid_from' => $season->starts_on,
                    'status' => ScholarshipStatus::Approved,
                    'decided_by' => User::query()->where('email', 'admin@academia.test')->value('id'),
                    'decided_at' => now(),
                ],
            );
        }

        $last = $organization->today()->startOfMonth()->min(CarbonImmutable::parse($season->ends_on)->startOfMonth());

        for ($period = CarbonImmutable::parse($season->starts_on)->startOfMonth(); $period->lte($last); $period = $period->addMonth()) {
            app(GenerateMonthlyCharges::class)->handle($organization, $period);
        }
    }
}
