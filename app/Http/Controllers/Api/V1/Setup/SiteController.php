<?php

namespace App\Http\Controllers\Api\V1\Setup;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Venue;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Lugares y sus canchas (salas, aulas): dónde se dan las clases.
 */
class SiteController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Site::query()->with('venues')->orderBy('name')->get()->map(fn (Site $site) => self::item($site))->values(),
        ]);
    }

    /**
     * Lugar nuevo con sus canchas; sin canchas, una con el nombre del lugar.
     */
    public function store(Request $request, CurrentOrganization $current): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('sites', 'name')->where('organization_id', $current->id())],
            'address' => ['nullable', 'string', 'max:255'],
            'spaces' => ['nullable', 'array', 'max:30'],
            'spaces.*' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
        ], [
            'name.required' => 'Ingresá el nombre del lugar.',
            'name.unique' => 'Ya hay un lugar con ese nombre.',
            'spaces.*.distinct' => 'Hay nombres repetidos.',
        ]);

        $site = DB::transaction(function () use ($data) {
            $site = Site::query()->create(['name' => trim($data['name']), 'address' => $data['address'] ?? null]);
            $spaces = collect($data['spaces'] ?? [])->map(fn (string $name) => trim($name))->filter();

            foreach ($spaces->isEmpty() ? collect([$site->name]) : $spaces as $name) {
                Venue::query()->create(['site_id' => $site->id, 'name' => $name]);
            }

            return $site;
        });

        return response()->json(['data' => self::item($site->load('venues'))], Response::HTTP_CREATED);
    }

    /**
     * Otra cancha en un lugar que ya existe.
     */
    public function addSpace(Request $request, int $site): JsonResponse
    {
        $site = Site::query()->findOrFail($site);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('venues', 'name')->where('site_id', $site->id)],
        ], ['name.unique' => 'Ya existe en ese lugar.']);

        Venue::query()->create(['site_id' => $site->id, 'name' => trim($data['name'])]);

        return response()->json(['data' => self::item($site->load('venues'))], Response::HTTP_CREATED);
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'address' => $site->address,
            'spaces' => $site->venues->map(fn (Venue $venue) => [
                'id' => $venue->id,
                'name' => $venue->name,
                'label' => $venue->label,
            ])->values(),
        ];
    }
}
