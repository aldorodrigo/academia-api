<?php

namespace App\Actions\Invitations;

use App\Enums\OrganizationRole;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Crea (o renueva) una invitación y la envía por email.
 *
 * Devuelve el token en claro: es la única vez que existe, para mostrar el
 * link y el QR en el panel.
 */
class CreateInvitation
{
    public function __construct(private CurrentOrganization $current) {}

    /**
     * @param  list<array{role: string, starts_on?: ?string, ends_on?: ?string}>  $roles
     * @return array{0: Invitation, 1: string}
     */
    public function handle(Organization $organization, string $email, array $roles, ?User $invitedBy = null): array
    {
        $roles = $this->normalizeRoles($roles);
        $email = mb_strtolower(trim($email));

        return $this->current->run($organization, function (Organization $organization) use ($email, $roles, $invitedBy) {
            // Una sola invitación pendiente por persona: la nueva reemplaza a las anteriores.
            Invitation::query()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->each(fn (Invitation $old) => $old->update(['revoked_at' => now()]));

            $token = Invitation::newToken();

            $invitation = Invitation::query()->create([
                'organization_id' => $organization->id,
                'email' => $email,
                'roles' => $roles,
                'token_hash' => Invitation::hashToken($token),
                'invited_by' => $invitedBy?->id,
                'expires_at' => now()->addDays(Invitation::VALID_DAYS),
            ]);

            Mail::to($email)->queue(new InvitationMail($invitation, $token));

            return [$invitation, $token];
        });
    }

    /**
     * Token nuevo y vencimiento renovado para una invitación pendiente o vencida.
     */
    public function resend(Invitation $invitation): string
    {
        [, $token] = $this->handle($invitation->organization, $invitation->email, $invitation->roles, $invitation->invitedBy);

        return $token;
    }

    /**
     * @param  list<array{role: string, starts_on?: ?string, ends_on?: ?string}>  $roles
     * @return list<array{role: string, starts_on: ?string, ends_on: ?string}>
     */
    private function normalizeRoles(array $roles): array
    {
        if ($roles === []) {
            throw ValidationException::withMessages(['roles' => 'Elegí al menos un rol.']);
        }

        return collect($roles)->map(function (array $role) {
            $startsOn = filled($role['starts_on'] ?? null) ? Carbon::parse($role['starts_on'])->toDateString() : null;
            $endsOn = filled($role['ends_on'] ?? null) ? Carbon::parse($role['ends_on'])->toDateString() : null;

            if (OrganizationRole::tryFrom($role['role'])?->isBoardPosition() && $endsOn === null) {
                throw ValidationException::withMessages([
                    'roles' => 'Un cargo de comisión necesita la fecha de fin del mandato.',
                ]);
            }

            return ['role' => $role['role'], 'starts_on' => $startsOn, 'ends_on' => $endsOn];
        })->unique('role')->values()->all();
    }
}
