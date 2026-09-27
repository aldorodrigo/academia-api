<?php

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Enums\GroupCriterion;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Season;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pase de temporada: reinscribe en la temporada de destino a los jugadores de la de origen.
 *
 * Las inscripciones de origen no se tocan: al dejar de ser la temporada actual quedan
 * finalizadas solas (Enrollment::isFinished). Los que no se reinscriben quedan como están.
 */
class TransferSeason
{
    /** Estados que pasan a la temporada nueva (no las bajas ni los suspendidos). */
    public const TRANSFERABLE = [EnrollmentStatus::Active, EnrollmentStatus::Scholarship, EnrollmentStatus::Pending];

    /**
     * Filas propuestas: una por jugador y disciplina, con la categoría sugerida para la
     * nueva temporada y el mismo estado. Las ya reinscriptas vienen destildadas.
     *
     * @return Collection<int, array{enrollment_id: int, player: string, from_group: string, group_id: int, status: string, include: bool, already: bool}>
     */
    public function candidates(Season $from, Season $to): Collection
    {
        $alreadyIn = Enrollment::query()
            ->where('season_id', $to->id)
            ->with('group')
            ->get()
            ->map(fn (Enrollment $e) => "{$e->student_id}-{$e->group->program_id}")
            ->flip();

        return Enrollment::query()
            ->with(['student', 'group.program'])
            ->where('season_id', $from->id)
            ->whereIn('status', self::TRANSFERABLE)
            ->get()
            ->unique(fn (Enrollment $e) => "{$e->student_id}-{$e->group->program_id}")
            ->sortBy(fn (Enrollment $e) => [$e->group->program->name, $e->group->name, $e->student->last_name, $e->student->first_name])
            ->map(function (Enrollment $e) use ($to, $alreadyIn) {
                $already = $alreadyIn->has("{$e->student_id}-{$e->group->program_id}");

                return [
                    'enrollment_id' => $e->id,
                    'player' => "{$e->student->last_name}, {$e->student->first_name}",
                    'from_group' => "{$e->group->name} · {$e->group->program->name}",
                    'group_id' => $this->nextGroup($e, $to)->id,
                    'status' => $e->status->value,
                    'include' => ! $already,
                    'already' => $already,
                ];
            })
            ->values();
    }

    /**
     * @param  list<array{enrollment_id: int|string, group_id: int|string, status: string, include?: bool}>  $rows
     * @return int inscripciones creadas
     */
    public function handle(Season $from, Season $to, array $rows): int
    {
        return DB::transaction(function () use ($from, $to, $rows) {
            $created = 0;

            foreach ($rows as $row) {
                if (! ($row['include'] ?? false)) {
                    continue;
                }

                $source = Enrollment::query()->where('season_id', $from->id)->find($row['enrollment_id']);
                $group = Group::query()->find($row['group_id']);

                if ($source === null || $group === null) {
                    continue;
                }

                $enrollment = Enrollment::query()->firstOrCreate(
                    ['student_id' => $source->student_id, 'group_id' => $group->id, 'season_id' => $to->id],
                    ['status' => EnrollmentStatus::from($row['status']), 'enrolled_on' => $to->starts_on],
                );

                $created += $enrollment->wasRecentlyCreated ? 1 : 0;
            }

            return $created;
        });
    }

    /**
     * Por edad: la que corresponde en la nueva temporada (Sub-10 → Sub-12). Por nivel, o si
     * no hay sugerencia: la misma.
     */
    private function nextGroup(Enrollment $enrollment, Season $to): Group
    {
        $group = $enrollment->group;

        if ($group->program->group_criterion !== GroupCriterion::BirthYear || $enrollment->student->birth_date === null) {
            return $group;
        }

        return Group::suggestFor($enrollment->student->birth_date, $to, $group->program) ?? $group;
    }
}
