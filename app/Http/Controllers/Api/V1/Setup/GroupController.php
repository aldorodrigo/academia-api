<?php

namespace App\Http\Controllers\Api\V1\Setup;

use App\Actions\Academic\SaveGroups;
use App\Enums\GroupCriterion;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Venue;
use App\Support\Onboarding\Templates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Paso 2 de la guía: categorías y horarios.
 */
class GroupController extends Controller
{
    public function index(): JsonResponse
    {
        $groups = Group::query()
            ->with(['program', 'schedules.venue', 'instructors'])
            ->withCount('enrollments')
            ->join('programs', 'programs.id', '=', 'groups.program_id')
            ->orderBy('programs.name')
            ->orderBy('groups.name')
            ->select('groups.*')
            ->get();

        return response()->json(['data' => $groups->map(fn (Group $group) => self::item($group))->values()]);
    }

    /**
     * Nombres sugeridos por edad o por nivel (sin los que ya existen en la disciplina).
     */
    public function suggestions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'program_id' => ['required', 'integer'],
            'ages' => ['nullable', 'array'],
            'ages.from' => ['required_with:ages', 'integer', 'min:3', 'max:99'],
            'ages.to' => ['required_with:ages', 'integer', 'min:3', 'max:99', 'gte:ages.from'],
            'ages.span' => ['required_with:ages', 'integer', 'min:1', 'max:5'],
            'levels' => ['nullable', 'array', 'max:20'],
            'levels.*' => ['string', 'max:50'],
        ]);
        $program = Program::query()->findOrFail($data['program_id']);
        $existing = $program->groups()->pluck('name')->map(fn (string $name) => mb_strtolower($name));

        $suggestions = $program->group_criterion === GroupCriterion::BirthYear || isset($data['ages'])
            ? Templates::groupsByAge(...($data['ages'] ?? Templates::ages()))
            : Templates::groupsByLevel($data['levels'] ?? Templates::levels());

        return response()->json([
            'data' => array_values(array_filter(
                $suggestions,
                fn (array $group) => ! $existing->contains(mb_strtolower($group['name'])),
            )),
        ]);
    }

    public function store(Request $request, SaveGroups $save): JsonResponse
    {
        $data = $request->validate([
            'program_id' => ['required', 'integer'],
            'groups' => ['required', 'array', 'min:1', 'max:40'],
            'groups.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            ...self::groupRules('groups.*.'),
            'venue' => ['nullable', 'array'],
            'venue.id' => ['nullable', 'integer'],
            'venue.name' => ['nullable', 'string', 'max:255'],
            'venue.address' => ['nullable', 'string', 'max:255'],
        ], self::messages('groups.*.'));

        $program = Program::query()->findOrFail($data['program_id']);
        $groups = $save->create($program, $data['groups'], $data['venue'] ?? null);

        return response()->json([
            'data' => $groups->map(fn (Group $group) => self::item($group->load(['program', 'schedules.venue', 'instructors'])->loadCount('enrollments')))->values(),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, SaveGroups $save, int $group): JsonResponse
    {
        $group = Group::query()->findOrFail($group);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            ...self::groupRules(''),
            'schedules.*.venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->where('organization_id', $group->organization_id)],
        ], self::messages(''));

        $save->update($group, $data);

        return response()->json(['data' => self::item($group->fresh(['program', 'schedules.venue', 'instructors'])->loadCount('enrollments'))]);
    }

    public function destroy(int $group): Response
    {
        $group = Group::query()->withCount('enrollments')->findOrFail($group);

        if ($group->enrollments_count > 0) {
            throw ValidationException::withMessages(['group' => 'Tiene inscripciones: desactivala en vez de borrarla.']);
        }

        $group->schedules()->delete();
        $group->instructors()->detach();
        $group->delete();

        return response()->noContent();
    }

    public function storeVenue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $venue = Venue::query()->firstOrCreate(['name' => trim($data['name'])], ['address' => $data['address'] ?? null]);

        return response()->json(['data' => ['id' => $venue->id, 'name' => $venue->name]], Response::HTTP_CREATED);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function groupRules(string $prefix): array
    {
        return [
            "{$prefix}min_age" => ['nullable', 'integer', 'min:3', 'max:99'],
            "{$prefix}max_age" => ['nullable', 'integer', 'min:3', 'max:99', "gte:{$prefix}min_age"],
            "{$prefix}level" => ['nullable', 'string', 'max:255'],
            "{$prefix}capacity" => ['nullable', 'integer', 'min:1', 'max:1000'],
            "{$prefix}schedules" => ['nullable', 'array', 'max:14'],
            "{$prefix}schedules.*.weekday" => ['required', 'integer', 'between:1,7'],
            "{$prefix}schedules.*.starts_at" => ['required', 'date_format:H:i'],
            "{$prefix}schedules.*.ends_at" => ['required', 'date_format:H:i', "after:{$prefix}schedules.*.starts_at"],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function messages(string $prefix): array
    {
        return [
            "{$prefix}name.distinct" => 'Hay nombres repetidos.',
            "{$prefix}max_age.gte" => 'La edad "hasta" no puede ser menor que "desde".',
            "{$prefix}schedules.*.ends_at.after" => 'El horario tiene que terminar después de empezar.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(Group $group): array
    {
        return [
            'id' => $group->id,
            'program' => ['id' => $group->program->id, 'name' => $group->program->name],
            'name' => $group->name,
            'min_age' => $group->min_age,
            'max_age' => $group->max_age,
            'level' => $group->level,
            'capacity' => $group->capacity,
            'is_active' => $group->is_active,
            'schedules' => $group->schedules->map(fn (Schedule $schedule) => [
                'weekday' => $schedule->weekday,
                'starts_at' => Schedule::time($schedule->starts_at),
                'ends_at' => Schedule::time($schedule->ends_at),
                'venue' => $schedule->venue === null ? null : ['id' => $schedule->venue->id, 'name' => $schedule->venue->name],
            ])->values(),
            'instructors' => $group->instructors->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values(),
            'enrollments_count' => (int) ($group->enrollments_count ?? 0),
        ];
    }
}
