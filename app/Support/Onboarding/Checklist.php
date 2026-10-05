<?php

namespace App\Support\Onboarding;

use App\Enums\OrganizationType;
use App\Filament\Support\Terms;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Season;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Vocabulary;

/**
 * Guía "Primeros pasos": cada paso está hecho si existe lo que pide (aunque se haya hecho
 * fuera de la guía). La leen el panel y la app (`GET onboarding`).
 */
class Checklist
{
    /** Pasos en orden. */
    public const STEPS = ['programs', 'groups', 'season', 'instructors'];

    /** Pasos que se pueden dejar para después. */
    public const SKIPPABLE = ['instructors'];

    public function __construct(private Organization $organization) {}

    public static function for(Organization $organization): self
    {
        return new self($organization);
    }

    /**
     * @return array{steps: list<array<string, mixed>>, done: int, total: int, next: ?string, completed: bool, dismissed: bool, terminology_suggestion: ?array<string, mixed>}
     */
    public function toArray(): array
    {
        $steps = app(CurrentOrganization::class)->run($this->organization, fn () => $this->steps());
        $finished = array_filter($steps, fn (array $step) => in_array($step['status'], ['done', 'skipped'], true));
        $next = collect($steps)->first(fn (array $step) => ! in_array($step['status'], ['done', 'skipped'], true));
        $completed = count($finished) === count($steps);

        // La primera vez que se completa queda registrado (y la guía deja de abrirse sola).
        if ($completed && $this->organization->onboarding_completed_at === null) {
            $this->organization->forceFill(['onboarding_completed_at' => now()])->save();
        }

        return [
            'steps' => $steps,
            'done' => count($finished),
            'total' => count($steps),
            'next' => $next['key'] ?? null,
            'completed' => $completed,
            'dismissed' => $this->organization->onboarding_dismissed_at !== null,
            // Una academia que enseña fútbol: ¿categoría, técnico y cancha?
            'terminology_suggestion' => VocabularySuggestion::for($this->organization),
        ];
    }

    public function isSkipped(string $key): bool
    {
        return in_array($key, self::SKIPPABLE, true)
            && in_array($key, $this->organization->onboarding_skipped ?? [], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function steps(): array
    {
        $organization = $this->organization;
        $group = Terms::pluralize(mb_strtolower($organization->term('group')));
        $instructor = Terms::pluralize(mb_strtolower($organization->term('instructor')));

        $programs = Program::query()->orderBy('name')->pluck('name');
        $groups = Group::query()->where('is_active', true)->whereHas('schedules')->count();
        $withoutSchedule = Group::query()->where('is_active', true)->whereDoesntHave('schedules')->count();
        $groupWord = fn (int $count) => $count === 1 ? mb_strtolower($organization->term('group')) : $group;
        $seasons = Season::query()->open()->orderBy('starts_on')->get();
        $instructors = Team::instructors($organization);
        $invitations = Team::invitations($organization, $instructors);

        $definitions = [
            'programs' => [
                'title' => '¿Qué enseñan?',
                'description' => ucfirst(Terms::pluralize(mb_strtolower($organization->term('program'))))
                    .' que ofrece '.($organization->type ?? OrganizationType::Club)->withArticle().'.',
                'done' => $programs->isNotEmpty(),
                'blocked_by' => null,
                'summary' => $this->list($programs->all()),
                'minutes' => 1,
            ],
            'groups' => [
                'title' => ucfirst($group).' y horarios',
                'description' => 'Las familias eligen '.Vocabulary::gendered($organization->term('group'), 'el', 'la').' '.mb_strtolower($organization->term('group')).' al inscribirse.',
                'done' => $groups > 0,
                'blocked_by' => $programs->isEmpty() ? 'programs' : null,
                'summary' => $groups > 0
                    ? ($groups + $withoutSchedule).' '.$groupWord($groups + $withoutSchedule).($withoutSchedule > 0 ? " · {$withoutSchedule} sin horario" : '')
                    : null,
                'minutes' => 3,
            ],
            'season' => [
                'title' => 'Temporada y cuotas',
                'description' => 'Cuándo empieza, cuánto dura y cuánto se cobra.',
                'done' => $seasons->isNotEmpty(),
                'blocked_by' => $programs->isEmpty() ? 'programs' : null,
                'summary' => $seasons->isEmpty() ? null
                    : $this->list($seasons->pluck('name')->all()).($seasons->contains(fn (Season $season) => $season->hasFeePlan()) ? '' : ' · sin cuotas'),
                'minutes' => 3,
            ],
            'instructors' => [
                'title' => ucfirst($instructor),
                'description' => Vocabulary::gendered($organization->term('instructor'), 'Invitalos', 'Invitalas').' para que tomen asistencia desde la app.',
                'done' => $instructors->isNotEmpty() || $invitations->isNotEmpty(),
                'blocked_by' => $groups === 0 ? 'groups' : null,
                'summary' => $this->teamSummary($instructors->count(), $invitations->count(), $instructor),
                'minutes' => 2,
            ],
        ];

        return collect(self::STEPS)->map(fn (string $key) => [
            'key' => $key,
            'title' => $definitions[$key]['title'],
            'description' => $definitions[$key]['description'],
            'status' => match (true) {
                $definitions[$key]['done'] => 'done',
                $this->isSkipped($key) => 'skipped',
                $definitions[$key]['blocked_by'] !== null => 'locked',
                default => 'pending',
            },
            'required' => ! in_array($key, self::SKIPPABLE, true),
            'skippable' => in_array($key, self::SKIPPABLE, true),
            'blocked_by' => $definitions[$key]['done'] ? null : $definitions[$key]['blocked_by'],
            'summary' => $definitions[$key]['done'] ? $definitions[$key]['summary'] : null,
            'minutes' => $definitions[$key]['minutes'],
        ])->all();
    }

    /**
     * "Fútbol", "Fútbol y Básquet", "Fútbol, Básquet y 2 más".
     *
     * @param  list<string>  $items
     */
    private function list(array $items): ?string
    {
        return match (true) {
            $items === [] => null,
            count($items) <= 2 => implode(' y ', $items),
            count($items) === 3 => "{$items[0]}, {$items[1]} y {$items[2]}",
            default => "{$items[0]}, {$items[1]} y ".(count($items) - 2).' más',
        };
    }

    private function teamSummary(int $active, int $invited, string $plural): ?string
    {
        $parts = array_filter([
            $active > 0 ? "{$active} ".($active === 1 ? mb_strtolower($this->organization->term('instructor')) : $plural) : null,
            $invited > 0 ? "{$invited} ".($invited === 1 ? 'invitado' : 'invitados') : null,
        ]);

        return $parts === [] ? null : implode(' y ', $parts);
    }
}
