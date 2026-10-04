<?php

namespace Database\Seeders;

use App\Actions\Billing\IssueSeasonCharges;
use App\Actions\Billing\RegisterPayment;
use App\Enums\DailyBasis;
use App\Enums\DailyGrouping;
use App\Enums\DiscountType;
use App\Enums\EnrollmentStatus;
use App\Enums\FeeFrequency;
use App\Enums\GroupCriterion;
use App\Enums\MoneyAccountType;
use App\Enums\PaymentMethod;
use App\Enums\ScholarshipStatus;
use App\Enums\SeasonKind;
use App\Models\DiscountRule;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Scholarship;
use App\Models\Season;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Datos de desarrollo de cobros de la organización activa: tarifas, hermanos −20 %,
 * beca del 50 % para Sofía, cuota mensual desde el inicio de la temporada hasta el mes
 * actual y una colonia de verano (Fútbol y Pádel) por día de entrenamiento, agrupada por
 * semana, con las cuotas creadas por adelantado. Idempotente.
 */
class BillingSeeder extends Seeder
{
    public function run(): void
    {
        $organization = app(CurrentOrganization::class)->get();
        $season = Season::query()->where('name', '2026')->first();

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

        $pronto = DiscountRule::query()->firstOrCreate(
            ['type' => DiscountType::EarlyPayment],
            ['name' => 'Pronto pago', 'percent' => 10, 'until_day' => 5, 'valid_from' => $season->starts_on],
        );
        $pronto->feeConcepts()->syncWithoutDetaching([$monthly->id]);

        // Cuota mensual al empezar cada mes; se emiten todos los meses desde el inicio.
        $season->update(['kind' => SeasonKind::Annual, 'fee_frequency' => FeeFrequency::Monthly, 'due_days' => 9]);
        Enrollment::query()->where('season_id', $season->id)->with(['season', 'group', 'student', 'organization'])->get()
            ->each(fn (Enrollment $enrollment) => app(IssueSeasonCharges::class)->forEnrollment($enrollment, from: $season->starts_on));

        // Un pago de la familia Benítez que salda los primeros meses y deja saldo a favor.
        $bank = MoneyAccount::query()->firstOrCreate(['name' => 'Banco Itaú'], ['type' => MoneyAccountType::Bank]);
        // Datos que ve el tutor para transferir antes de informar el pago.
        if (blank($bank->transfer_details)) {
            $bank->update(['transfer_details' => "Cuenta corriente 1234567\nTitular: Club Jakare\nRUC 80012345-6"]);
        }
        $family = Family::query()->where('name', 'Familia Benítez')->first();

        if ($family !== null && $family->payments()->doesntExist()) {
            app(RegisterPayment::class)->handle(
                $family, $bank, 1_000_000, PaymentMethod::Transfer, CarbonImmutable::parse($season->starts_on)->addMonths(2)->setDay(3),
                payer: $family->guardians()->first(), reference: 'Transferencia 4471',
            );
        }

        $this->summerCamp($organization);
    }

    /**
     * Colonia de verano 2027: Fútbol y Pádel, ₲ 20.000 por día de entrenamiento, una cuota
     * por semana, todas creadas al inscribir. Mateo también está en la temporada anual.
     */
    private function summerCamp(Organization $organization): void
    {
        $futbol = Program::query()->where('name', 'Fútbol')->first();
        $padel = Program::query()->firstOrCreate(['name' => 'Pádel'], ['group_criterion' => GroupCriterion::Level]);
        $kids = $padel->groups()->firstOrCreate(['name' => 'Pádel Kids'], ['organization_id' => $organization->id]);

        if ($kids->schedules()->doesntExist()) {
            foreach ([2, 4] as $weekday) {
                $kids->schedules()->create(['weekday' => $weekday, 'starts_at' => '09:00', 'ends_at' => '10:30']);
            }
        }

        $camp = Season::query()->firstOrCreate(['name' => 'Colonia de verano 2027'], [
            'kind' => SeasonKind::Fortnightly,
            'starts_on' => '2027-01-04',
            'ends_on' => '2027-01-17',
            'fee_frequency' => FeeFrequency::Daily,
            'daily_basis' => DailyBasis::Training,
            'daily_grouping' => DailyGrouping::Week,
            'due_days' => 3,
            'issue_upfront' => true,
        ]);
        $camp->programs()->syncWithoutDetaching(array_filter([$futbol?->id, $padel->id]));

        Tariff::query()->firstOrCreate(
            ['fee_concept_id' => FeeConcept::monthlyFee($organization)->id, 'season_id' => $camp->id, 'group_id' => null, 'valid_from' => $camp->starts_on],
            ['amount' => 20000],
        );

        $mateo = Enrollment::query()->where('season_id', '!=', $camp->id)
            ->whereHas('student', fn ($query) => $query->where('document', '6123456'))->first();

        if ($mateo !== null) {
            Enrollment::query()->firstOrCreate(
                ['student_id' => $mateo->student_id, 'group_id' => $mateo->group_id, 'season_id' => $camp->id],
                ['status' => EnrollmentStatus::Active, 'enrolled_on' => $organization->today()],
            );
        }
    }
}
