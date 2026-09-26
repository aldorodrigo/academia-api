<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;

class CurrentOrganizationController extends Controller
{
    /**
     * Datos y configuración de la organización activa (header X-Organization).
     */
    public function __invoke(CurrentOrganization $current): JsonResponse
    {
        $organization = $current->get();

        return response()->json([
            'data' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'type' => $organization->type,
                'currency' => $organization->currency,
                'timezone' => $organization->timezone,
                'terminology' => array_merge($organization::DEFAULT_TERMINOLOGY, $organization->terminology ?? []),
                'features' => $organization->features ?? [],
            ],
        ]);
    }
}
