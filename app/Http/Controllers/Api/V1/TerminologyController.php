<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organizations\UpdateTerminology;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Cómo les dicen" (administrador): las palabras de las pantallas de la app y del panel.
 */
class TerminologyController extends Controller
{
    public function update(Request $request, CurrentOrganization $current, UpdateTerminology $update): JsonResponse
    {
        $data = $request->validate([
            'terminology' => ['present', 'array:'.implode(',', UpdateTerminology::KEYS)],
            'terminology.*' => ['nullable', 'string', 'max:30'],
            // Formas femeninas de las palabras de persona, si la regla no alcanza (vacía = la de la regla).
            'feminine' => ['sometimes', 'array:'.implode(',', Organization::PERSON_TERMS)],
            'feminine.*' => ['nullable', 'string', 'max:30'],
        ], [
            'terminology.*.max' => 'Usá una palabra de hasta 30 letras.',
            'feminine.*.max' => 'Usá una palabra de hasta 30 letras.',
        ]);

        $organization = $update->handle($current->get(), $data['terminology'], $data['feminine'] ?? null);

        return response()->json([
            'data' => [
                'terminology' => array_merge(Organization::DEFAULT_TERMINOLOGY, $organization->terminology ?? []),
                'terminology_feminine' => (object) ($organization->terminology_feminine ?? []),
                'vocabulary' => $organization->vocabulary(),
            ],
        ]);
    }
}
