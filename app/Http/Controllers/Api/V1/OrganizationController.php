<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organizations\RegisterOrganization;
use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\Organizations\Slug;
use App\Support\Vocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Alta autoservicio del club (sin organización activa).
 */
class OrganizationController extends Controller
{
    /**
     * Identificador libre a partir de un nombre o de lo que escribió el usuario.
     */
    public function slug(Request $request): JsonResponse
    {
        $slug = Slug::normalize((string) $request->query('value', ''));
        $available = Slug::isAvailable($slug);

        return response()->json([
            'slug' => $slug,
            'available' => $available,
            'suggestion' => $available ? null : Slug::suggest($slug),
        ]);
    }

    public function store(Request $request, RegisterOrganization $register): JsonResponse
    {
        abort_unless($request->user()->isVerified(), Response::HTTP_FORBIDDEN, 'Verificá tu cuenta para crear un club.');

        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'slug' => Slug::rules(),
            'terminology' => ['nullable', 'array:'.implode(',', array_keys(Organization::DEFAULT_TERMINOLOGY))],
            'terminology.*' => ['nullable', 'string', 'max:30'],
        ], [
            // Todavía no existe: el tipo es el que eligió en "Tu club".
            'name.required' => 'Ingresá el nombre '.Vocabulary::of(OrganizationType::tryFrom((string) $request->input('type'))?->noun() ?? 'club').'.',
            ...Slug::messages(),
        ]);

        $organization = $register->handle($request->user(), $data);

        return response()->json([
            'data' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'type' => $organization->type,
            ],
        ], Response::HTTP_CREATED);
    }
}
