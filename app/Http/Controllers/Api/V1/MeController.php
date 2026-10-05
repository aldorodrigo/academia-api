<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    /**
     * Usuario autenticado y organizaciones a las que puede entrar.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'verified' => $user->isVerified(),
                // Opcional (female / male; null = sin especificar), para nombrarlo bien: "Técnica", "Tesorera".
                'gender' => $user->gender?->value,
                'organizations' => $user->activeOrganizations()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Organization $organization) => [
                        'slug' => $organization->slug,
                        'name' => $organization->name,
                        'type' => $organization->type,
                    ]),
            ],
        ]);
    }

    /**
     * "Mi cuenta": por ahora, el género (opcional).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gender' => ['present', 'nullable', Rule::enum(Gender::class)],
        ], [
            'gender.enum' => 'Elegí Femenino, Masculino o Sin especificar.',
        ]);

        $request->user()->forceFill(['gender' => Gender::parse($data['gender'])])->save();

        return $this->show($request);
    }
}
