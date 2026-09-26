<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitations\AcceptInvitation;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcceptInvitationRequest;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class InvitationController extends Controller
{
    /**
     * Datos de la invitación para mostrarla antes de aceptarla.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = Invitation::findByToken($token);

        abort_unless($invitation?->canBeAccepted(), Response::HTTP_NOT_FOUND, 'La invitación no es válida o ya venció.');

        $organization = $invitation->organization;

        return response()->json([
            'data' => [
                'organization' => ['slug' => $organization->slug, 'name' => $organization->name],
                'email' => $invitation->email,
                'roles' => collect($invitation->roles)->map(fn (array $role) => [
                    'name' => $role['role'],
                    'label' => OrganizationRole::labelFor($role['role'], $organization),
                ])->values(),
                'user_exists' => User::query()->where('email', $invitation->email)->exists(),
                'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
            ],
        ]);
    }

    /**
     * Acepta la invitación y devuelve un token para la app.
     */
    public function accept(AcceptInvitationRequest $request, AcceptInvitation $accept): JsonResponse
    {
        [, $token] = $accept->handle($request->invitation, $request->validated());

        return response()->json([
            'token' => $token,
            'organization' => $request->invitation->organization->slug,
        ], Response::HTTP_CREATED);
    }
}
