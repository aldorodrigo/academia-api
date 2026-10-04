<?php

namespace App\Http\Controllers\Api\V1\Setup;

use App\Actions\Academic\CreatePrograms;
use App\Enums\GroupCriterion;
use App\Http\Controllers\Controller;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Paso 1 de la guía: disciplinas.
 */
class ProgramController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->list(Program::query()->withCount('groups')->orderBy('name')->get());
    }

    public function store(Request $request, CreatePrograms $create): JsonResponse
    {
        $data = $request->validate([
            'programs' => ['required', 'array', 'min:1', 'max:30'],
            'programs.*.name' => ['required', 'string', 'max:100'],
            'programs.*.group_criterion' => ['required', Rule::enum(GroupCriterion::class)],
        ], ['programs.required' => 'Elegí al menos una.']);

        return $this->list($create->handle($data['programs']), Response::HTTP_CREATED);
    }

    public function update(Request $request, int $program): JsonResponse
    {
        $program = Program::query()->findOrFail($program);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('programs', 'name')
                ->where('organization_id', $program->organization_id)->ignore($program->id)],
            'group_criterion' => ['required', Rule::enum(GroupCriterion::class)],
        ], ['name.unique' => 'Ya existe una con ese nombre.']);

        $program->update($data);

        return response()->json(['data' => $this->item($program->loadCount('groups'))]);
    }

    public function destroy(int $program): Response
    {
        $program = Program::query()->withCount('groups')->findOrFail($program);

        if ($program->groups_count > 0) {
            throw ValidationException::withMessages(['program' => 'Tiene categorías: borralas primero.']);
        }

        $program->delete();

        return response()->noContent();
    }

    /**
     * @param  Collection<int, Program>  $programs
     */
    private function list(Collection $programs, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json(['data' => $programs->map(fn (Program $program) => $this->item($program))->values()], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(Program $program): array
    {
        return [
            'id' => $program->id,
            'name' => $program->name,
            'group_criterion' => $program->group_criterion,
            'groups_count' => (int) $program->groups_count,
        ];
    }
}
