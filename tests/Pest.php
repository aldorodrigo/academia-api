<?php

use App\Actions\Billing\IssueSeasonCharges;
use App\Enums\FeeFrequency;
use App\Enums\MembershipStatus;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\Season;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Crea un usuario con membresía activa en la organización dada.
 */
function memberOf(Organization $organization, array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    $organization->memberships()->create([
        'user_id' => $user->id,
        'status' => MembershipStatus::Active,
    ]);

    return $user;
}

/**
 * Emite la cuota de un mes (AAAA-MM) a las inscripciones activas o becadas de las temporadas
 * vigentes, como si el generador corriera ese mes. Las temporadas sin plan pasan a tener
 * cuota mensual (así los tests preparan inscripciones, becas y descuentos antes de emitir).
 *
 * @return array{created: int, existing: int, full_scholarship: int, without_tariff: bool, amount: int}
 */
function issueMonth(Organization $organization, string $period, bool $dryRun = false): array
{
    $month = CarbonImmutable::parse("{$period}-01");
    $summary = ['created' => 0, 'existing' => 0, 'full_scholarship' => 0, 'without_tariff' => false, 'amount' => 0];

    app(CurrentOrganization::class)->run($organization, function () use ($month, $dryRun, &$summary) {
        Season::query()->active()->whereNull('fee_frequency')->update(['fee_frequency' => FeeFrequency::Monthly]);

        Enrollment::query()->billable()
            ->with(['season', 'group', 'student', 'organization'])->get()
            ->each(function (Enrollment $enrollment) use ($month, $dryRun, &$summary) {
                $result = app(IssueSeasonCharges::class)->forEnrollment($enrollment, until: $month, from: $month, dryRun: $dryRun);

                foreach (['created', 'existing', 'full_scholarship', 'amount'] as $key) {
                    $summary[$key] += $result[$key];
                }
                $summary['without_tariff'] = $summary['without_tariff'] || $result['without_tariff'];
            });
    });

    return $summary;
}
