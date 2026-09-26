<?php

namespace App\Actions\Platform;

use App\Actions\Invitations\CreateInvitation;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

/**
 * Alta de una organización desde la plataforma: se crea (con sus roles base)
 * y se invita a su primer administrador.
 */
class CreateOrganization
{
    public function __construct(private CreateInvitation $createInvitation) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Organization, 1: string} organización y token de la invitación
     */
    public function handle(array $attributes, string $adminEmail, ?User $actor = null): array
    {
        $organization = Organization::query()->create($attributes);

        activity('platform')->performedOn($organization)->causedBy($actor)
            ->withProperties(['admin_email' => $adminEmail])
            ->log('Organización creada');

        [, $token] = $this->createInvitation->handle(
            $organization,
            $adminEmail,
            [['role' => OrganizationRole::Admin->value]],
            $actor,
        );

        return [$organization, $token];
    }
}
