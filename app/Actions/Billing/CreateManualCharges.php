<?php

namespace App\Actions\Billing;

use App\Models\Enrollment;
use App\Models\FeeConcept;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cargos sueltos (torneo, indumentaria…) para varios jugadores o para toda una
 * categoría (sus inscripciones facturables). Monto fijo, sin descuentos automáticos.
 */
class CreateManualCharges
{
    public function __construct(
        private CurrentOrganization $current,
        private IssueCharge $issue,
    ) {}

    /**
     * @param  list<int>  $studentIds
     * @return int cargos creados
     */
    public function handle(
        Organization $organization,
        FeeConcept $concept,
        int $amount,
        string $description,
        CarbonImmutable $dueOn,
        array $studentIds = [],
        ?Group $group = null,
        ?User $createdBy = null,
    ): int {
        if ($amount <= 0) {
            throw new InvalidArgumentException('El monto tiene que ser mayor a cero.');
        }

        return $this->current->run($organization, function (Organization $organization) use ($concept, $amount, $description, $dueOn, $studentIds, $group, $createdBy) {
            $targets = $group !== null
                ? Enrollment::query()->billable()->where('group_id', $group->id)->get()
                    ->map(fn (Enrollment $e) => ['student_id' => $e->student_id, 'enrollment_id' => $e->id, 'group_id' => $e->group_id])
                : Student::query()->whereKey($studentIds)->with('currentEnrollments')->get()
                    ->map(fn (Student $s) => [
                        'student_id' => $s->id,
                        'enrollment_id' => $s->currentEnrollments->first()?->id,
                        'group_id' => $s->currentEnrollments->first()?->group_id,
                    ]);

            return DB::transaction(fn () => $targets->each(fn (array $target) => $this->issue->handle([
                ...$target,
                'fee_concept_id' => $concept->id,
                'description' => $description,
                'base_amount' => $amount,
                'issued_on' => $organization->today()->toDateString(),
                'due_on' => $dueOn->toDateString(),
                'created_by' => $createdBy?->id,
            ]))->count());
        });
    }
}
