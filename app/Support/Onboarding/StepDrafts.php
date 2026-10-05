<?php

namespace App\Support\Onboarding;

use App\Models\OnboardingDraft;
use App\Models\Organization;
use Illuminate\Support\Arr;

/**
 * Borradores de los pasos de la guía ("Se guarda solo"): lo que el admin está armando y todavía no
 * creó (`OnboardingDraft`), para retomarlo desde cualquier dispositivo, en la app o en el panel.
 * Por ahora, el paso 2 (categorías y horarios). Al crear las categorías (`SaveGroups`) queda como
 * usado (soft delete): nunca se borra físicamente.
 *
 * Formato del paso `groups` (el mismo para la app y el panel):
 * `{ program_id, ages: {from, to, span}, levels: [..], capacity, groups: [{ name, min_age, max_age, level,
 * slots: [{ weekdays: [1..7], starts_at, ends_at, venue_id }] }] }`.
 */
class StepDrafts
{
    public const KEYS = ['groups'];

    /**
     * @return array{draft: ?array<string, mixed>, updated_at: ?string}
     */
    public static function get(Organization $organization, string $key): array
    {
        $saved = self::current($organization, $key);

        return ['draft' => $saved?->draft, 'updated_at' => $saved?->updated_at?->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{draft: ?array<string, mixed>, updated_at: ?string}
     */
    public static function put(Organization $organization, string $key, array $draft): array
    {
        $saved = self::current($organization, $key)
            ?? new OnboardingDraft(['organization_id' => $organization->id, 'step' => $key]);
        $saved->fill(['draft' => self::normalizeGroups($draft), 'updated_by' => auth()->id()])->save();

        return self::get($organization, $key);
    }

    /**
     * Ya no hace falta (se creó lo que pedía o se vació): queda como usado, no se borra.
     */
    public static function forget(Organization $organization, string $key): void
    {
        self::current($organization, $key)?->delete();
    }

    private static function current(Organization $organization, string $key): ?OnboardingDraft
    {
        return OnboardingDraft::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('organization_id', $organization->id)
            ->where('step', $key)
            ->latest('id')
            ->first();
    }

    /**
     * Solo lo que el paso entiende, con los tipos de siempre (lo que llega de afuera no se guarda tal cual).
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public static function normalizeGroups(array $draft): array
    {
        $int = fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;
        $time = fn (mixed $value, string $default): string => is_string($value) && preg_match('/^\d{2}:\d{2}/', $value) ? substr($value, 0, 5) : $default;

        return [
            'program_id' => $int($draft['program_id'] ?? null),
            'ages' => [
                'from' => $int($draft['ages']['from'] ?? null),
                'to' => $int($draft['ages']['to'] ?? null),
                'span' => $int($draft['ages']['span'] ?? null),
            ],
            'levels' => collect($draft['levels'] ?? [])->filter(fn ($level) => is_string($level))->map(fn (string $level) => mb_substr($level, 0, 100))->take(20)->values()->all(),
            'capacity' => $int($draft['capacity'] ?? null),
            'groups' => collect($draft['groups'] ?? [])->filter(fn ($group) => is_array($group))->take(40)->map(fn (array $group) => [
                'name' => mb_substr((string) ($group['name'] ?? ''), 0, 255),
                'min_age' => $int($group['min_age'] ?? null),
                'max_age' => $int($group['max_age'] ?? null),
                'level' => is_string($group['level'] ?? null) ? mb_substr($group['level'], 0, 100) : null,
                'slots' => collect($group['slots'] ?? [])->filter(fn ($slot) => is_array($slot))->take(10)->map(fn (array $slot) => [
                    'weekdays' => collect($slot['weekdays'] ?? [])->map($int)->filter(fn (?int $day) => $day !== null && $day >= 1 && $day <= 7)->unique()->sort()->values()->all(),
                    'starts_at' => $time($slot['starts_at'] ?? null, '17:00'),
                    'ends_at' => $time($slot['ends_at'] ?? null, '18:30'),
                    'venue_id' => $int($slot['venue_id'] ?? null),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * El borrador como estado del formulario del panel ("Categorías y horarios").
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public static function toPanelState(array $draft): array
    {
        $groups = collect($draft['groups'] ?? []);

        return [
            'program_id' => $draft['program_id'] ?? null,
            'from' => $draft['ages']['from'] ?? null,
            'to' => $draft['ages']['to'] ?? null,
            'span' => $draft['ages']['span'] ?? null,
            'levels' => $draft['levels'] ?? [],
            'capacity' => $draft['capacity'] ?? null,
            'groups' => $groups->map(fn (array $group) => Arr::only($group, ['name', 'min_age', 'max_age', 'level']))->all(),
            'plan' => $groups->map(fn (array $group) => [
                'name' => $group['name'],
                'slots' => collect($group['slots'] ?? [])->map(fn (array $slot) => [
                    'weekdays' => array_map('strval', $slot['weekdays'] ?? []),
                    'starts_at' => $slot['starts_at'] ?? '17:00',
                    'ends_at' => $slot['ends_at'] ?? '18:30',
                    'venue_id' => $slot['venue_id'] ?? null,
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * El estado del formulario del panel como borrador (null si no hay nada armado).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    public static function fromPanelState(array $state): ?array
    {
        $groups = collect($state['groups'] ?? [])->filter(fn ($group) => is_array($group) && filled($group['name'] ?? null))->values();

        if (blank($state['program_id'] ?? null) || $groups->isEmpty()) {
            return null;
        }

        $plan = collect($state['plan'] ?? [])->filter(fn ($item) => is_array($item))->keyBy('name');

        return self::normalizeGroups([
            'program_id' => $state['program_id'],
            'ages' => ['from' => $state['from'] ?? null, 'to' => $state['to'] ?? null, 'span' => $state['span'] ?? null],
            'levels' => $state['levels'] ?? [],
            'capacity' => $state['capacity'] ?? null,
            'groups' => $groups->map(fn (array $group) => [
                ...$group,
                'slots' => array_values($plan[$group['name']]['slots'] ?? []),
            ])->all(),
        ]);
    }
}
