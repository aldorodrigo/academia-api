<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Usuario autenticado y organizaciones a las que puede entrar.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'verified' => $user->isVerified(),
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
}
