<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Onboarding\Checklist;
use App\Support\Onboarding\Templates;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Guía "Primeros pasos": plantillas, checklist, omitir pasos y cerrarla.
 */
class OnboardingController extends Controller
{
    public function templates(): JsonResponse
    {
        return response()->json([
            'data' => [
                'organization_types' => Templates::organizationTypes(),
                'terminology_options' => Templates::terminologyOptions(),
                'programs' => Templates::programs(),
                'levels' => Templates::levels(),
                'ages' => Templates::ages(),
            ],
        ]);
    }

    public function show(CurrentOrganization $current): JsonResponse
    {
        return response()->json(['data' => Checklist::for($current->get())->toArray()]);
    }

    /**
     * `{ "dismissed": true }` cierra la guía (no se abre sola); `false` la vuelve a abrir.
     */
    public function update(Request $request, CurrentOrganization $current): JsonResponse
    {
        $data = $request->validate(['dismissed' => ['required', 'boolean']]);
        $organization = $current->get();

        $organization->forceFill(['onboarding_dismissed_at' => $data['dismissed'] ? now() : null])->save();

        return $this->show($current);
    }

    public function skip(Request $request, CurrentOrganization $current, string $key): JsonResponse
    {
        $data = $request->validate(['skipped' => ['required', 'boolean']]);
        validator(['key' => $key], ['key' => [Rule::in(Checklist::SKIPPABLE)]], [
            'key.in' => 'Este paso no se puede dejar para después.',
        ])->validate();

        $organization = $current->get();
        $skipped = collect($organization->onboarding_skipped ?? [])->reject(fn (string $item) => $item === $key);

        $organization->forceFill([
            'onboarding_skipped' => ($data['skipped'] ? $skipped->push($key) : $skipped)->values()->all(),
        ])->save();

        return $this->show($current);
    }
}
