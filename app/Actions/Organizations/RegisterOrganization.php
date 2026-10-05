<?php

namespace App\Actions\Organizations;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Support\Onboarding\Templates;
use App\Support\Roles\RoleAssigner;
use Illuminate\Support\Facades\DB;

/**
 * Alta autoservicio: el usuario (con el email verificado) crea su club y queda como
 * administrador. Paraguay, ₲ y Asunción; los módulos siguen siendo de la plataforma.
 */
class RegisterOrganization
{
    public function __construct(private RoleAssigner $assigner) {}

    /**
     * @param  array{name: string, type: string, slug: string, terminology?: array<string, string>}  $data
     */
    public function handle(User $user, array $data): Organization
    {
        $type = OrganizationType::from($data['type']);
        $terminology = array_merge(
            Templates::terminologyFor($type),
            array_filter($data['terminology'] ?? [], fn ($value) => filled($value)),
        );

        return DB::transaction(function () use ($user, $data, $type, $terminology) {
            // Los roles base, los conceptos de cobro y la Caja se crean solos (Organization::booted).
            $organization = Organization::query()->create([
                'name' => trim($data['name']),
                'slug' => $data['slug'],
                'type' => $type,
                'terminology' => $terminology,
                'self_service' => true,
            ]);

            // Eligió otras palabras que las del tipo: ya decidió, no se le proponen las de deporte.
            if ($terminology !== Templates::terminologyFor($type)) {
                $organization->forceFill(['terminology_confirmed_at' => now()])->save();
            }

            // Quien la crea es el dueño: lo que cobra en efectivo entra directo a la Caja (sin caja propia).
            $organization->forceFill(['owner_id' => $user->id])->save();
            $organization->memberships()->create(['user_id' => $user->id, 'status' => MembershipStatus::Active, 'collects_to_org_cash' => true]);
            $this->assigner->assign($organization, $user, OrganizationRole::Admin, assignedBy: $user);

            activity('platform')->performedOn($organization)->causedBy($user)->log('Organización creada (autoservicio)');

            return $organization;
        });
    }
}
