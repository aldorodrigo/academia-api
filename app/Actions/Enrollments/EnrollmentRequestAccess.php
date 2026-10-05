<?php

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Enums\MembershipStatus;
use App\Enums\MidPeriod;
use App\Filament\Support\MidPeriodPreview;
use App\Filament\Support\Terms;
use App\Models\Enrollment;
use App\Models\EnrollmentRequest;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Season;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Solicitudes de inscripción desde la app: quién las confirma, dónde se puede pedir lugar (disciplina,
 * temporada y categorías con la sugerida por edad) y el cupo. Lo usan la API y el panel.
 */
class EnrollmentRequestAccess
{
    /** En todas las categorías (secretario y prosecretario por defecto; el admin por Gate::before). */
    public const PERMISSION = 'Manage:EnrollmentRequests';

    /** Solo en las categorías donde es técnico (el técnico, por defecto). */
    public const GROUP_PERMISSION = 'Confirm:GroupEnrollments';

    /**
     * Si confirma alguna: en todas o en al menos una de sus categorías.
     */
    public static function canReviewAny(User $user): bool
    {
        return $user->can(self::PERMISSION)
            || ($user->can(self::GROUP_PERMISSION) && $user->instructedGroups()->exists());
    }

    public static function canReviewGroup(User $user, Group|int $group): bool
    {
        $groupId = $group instanceof Group ? $group->id : $group;

        return $user->can(self::PERMISSION)
            || ($user->can(self::GROUP_PERMISSION) && $user->instructedGroups()->where('groups.id', $groupId)->exists());
    }

    /**
     * Las solicitudes que puede confirmar: todas o las de sus categorías.
     *
     * @return Builder<EnrollmentRequest>
     */
    public static function reviewable(User $user): Builder
    {
        return EnrollmentRequest::query()->unless($user->can(self::PERMISSION), fn (Builder $query) => $user->can(self::GROUP_PERMISSION)
            ? $query->whereIn('group_id', $user->instructedGroups()->select('groups.id'))
            : $query->whereRaw('1 = 0'));
    }

    /**
     * Miembros activos que reciben el aviso de una solicitud nueva en esa categoría.
     *
     * @return Collection<int, User>
     */
    public static function reviewers(Organization $organization, Group $group): Collection
    {
        return app(CurrentOrganization::class)->run($organization, fn () => $organization->users()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->get()
            ->filter(fn (User $user) => self::canReviewGroup($user, $group))
            ->values());
    }

    /**
     * Temporada vigente o próxima y categoría activa de una de sus disciplinas (lo que eligió quien inscribe).
     *
     * @return array{0: Season, 1: Group}
     */
    public static function place(int $seasonId, int $groupId): array
    {
        $season = Season::query()->open()->find($seasonId);
        if ($season === null) {
            throw ValidationException::withMessages(['season_id' => 'Elegí una temporada vigente o próxima.']);
        }

        $group = Group::query()->where('is_active', true)->with('program')->find($groupId);
        if ($group === null || ! Season::query()->whereKey($season->id)->forProgram($group->program_id)->exists()) {
            throw ValidationException::withMessages(['group_id' => 'Elegí '.Terms::a('group', 'categoría').' de la temporada.']);
        }

        return [$season, $group];
    }

    /**
     * Una opción por disciplina y temporada vigente o próxima, con sus categorías activas.
     *
     * @return list<array<string, mixed>>
     */
    public static function options(?CarbonImmutable $birthDate = null): array
    {
        $seasons = Season::query()->open()->with('programs')->orderBy('starts_on')->get();
        $programs = Program::query()->orderBy('name')->get();
        $options = [];

        foreach ($programs as $program) {
            foreach ($seasons as $season) {
                if ($season->programs->isNotEmpty() && ! $season->programs->contains('id', $program->id)) {
                    continue;
                }

                $groups = self::groupOptions($season, $program, $birthDate);

                if ($groups === []) {
                    continue;
                }

                $options[] = [
                    'program' => ['id' => $program->id, 'name' => $program->name],
                    'season' => self::season($season),
                    'suggested_group_id' => collect($groups)->firstWhere('suggested', true)['id'] ?? null,
                    'groups' => $groups,
                ];
            }
        }

        return $options;
    }

    /**
     * Categorías activas de la disciplina con el cupo en la temporada.
     *
     * @return list<array<string, mixed>>
     */
    public static function groupOptions(Season $season, Program $program, ?CarbonImmutable $birthDate = null, ?int $exceptEnrollmentId = null): array
    {
        $groups = Group::query()->where('program_id', $program->id)->where('is_active', true)
            ->with('schedules')->orderBy('name')->get();
        $suggested = $birthDate ? Group::suggestFor($birthDate, $season, $program)?->id : null;

        return $groups->map(fn (Group $group) => [
            ...self::capacity($group, $season, $exceptEnrollmentId),
            'id' => $group->id,
            'name' => $group->name,
            'suggested' => $group->id === $suggested,
            'schedules' => $group->schedules->sortBy(['weekday', 'starts_at'])->map(fn ($schedule) => [
                'weekday' => $schedule->weekday,
                'starts_at' => substr((string) $schedule->starts_at, 0, 5),
                'ends_at' => substr((string) $schedule->ends_at, 0, 5),
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * Cupo de la categoría en la temporada: ocupan las inscripciones activas, becadas o pendientes.
     *
     * @return array{capacity: ?int, spots_left: ?int, full: bool}
     */
    public static function capacity(Group $group, Season $season, ?int $exceptEnrollmentId = null): array
    {
        if ($group->capacity === null) {
            return ['capacity' => null, 'spots_left' => null, 'full' => false];
        }

        $left = max(0, $group->capacity - self::occupied($group, $season, $exceptEnrollmentId));

        return ['capacity' => $group->capacity, 'spots_left' => $left, 'full' => $left === 0];
    }

    /**
     * @param  int|null  $exceptEnrollmentId  la inscripción pendiente de la solicitud que se confirma (ya ocupa lugar)
     */
    public static function occupied(Group $group, Season $season, ?int $exceptEnrollmentId = null): int
    {
        return Enrollment::query()
            ->where('group_id', $group->id)
            ->when($exceptEnrollmentId, fn ($query, $id) => $query->whereKeyNot($id))
            ->where('season_id', $season->id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Scholarship, EnrollmentStatus::Pending])
            ->count();
    }

    /**
     * Qué se cobra del período en curso si se inscribe hoy, o null si no empezó (o la temporada no tiene plan).
     *
     * @return array{label: string, default: string, options: list<array{value: string, label: string}>}|null
     */
    public static function midPeriod(Season $season, Group $group, CarbonImmutable $today): ?array
    {
        if (MidPeriodPreview::periodStarted($season, $group, $today) === null) {
            return null;
        }

        $unit = $season->billingUnit();

        return [
            'label' => 'Se inscribe '.$unit->midway().': se cobra',
            'default' => ($season->mid_period ?? MidPeriod::Full)->value,
            'options' => collect(MidPeriod::optionsFor($unit))
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()->all(),
        ];
    }

    /**
     * @return array{id: int, name: string, starts_on: string, ends_on: string}
     */
    public static function season(Season $season): array
    {
        return [
            'id' => $season->id,
            'name' => $season->name,
            'starts_on' => $season->starts_on->toDateString(),
            'ends_on' => $season->ends_on->toDateString(),
        ];
    }
}
