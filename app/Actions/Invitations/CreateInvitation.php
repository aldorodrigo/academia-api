<?php

namespace App\Actions\Invitations;

use App\Enums\OrganizationRole;
use App\Mail\InvitationMail;
use App\Models\Guardian;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Support\Phone;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Crea (o renueva) una invitación a un correo o a un celular. Con correo, la envía por email; con
 * celular, quien invita la comparte por WhatsApp (link `wa.me` al número, sin costo).
 *
 * Devuelve el token en claro: es la única vez que existe, para mostrar el
 * link y el QR en el panel.
 */
class CreateInvitation
{
    public function __construct(private CurrentOrganization $current) {}

    /**
     * Con $guardian, al aceptarla el tutor queda vinculado a la cuenta y ve a sus hijos.
     * Con $groupIds (técnico), queda asignado a esas categorías.
     *
     * @param  list<array{role: string, starts_on?: ?string, ends_on?: ?string}>  $roles
     * @param  list<int>  $groupIds
     * @return array{0: Invitation, 1: string}
     */
    public function handle(
        Organization $organization,
        ?string $email,
        array $roles,
        ?User $invitedBy = null,
        ?Guardian $guardian = null,
        ?string $name = null,
        array $groupIds = [],
        ?string $phone = null,
    ): array {
        $roles = $this->normalizeRoles($roles);
        $phone = filled($phone) ? Phone::mobile($phone) : null;
        // Va a uno solo: el celular si lo hay.
        $email = $phone === null && filled($email) ? mb_strtolower(trim($email)) : null;

        if ($phone === null && $email === null) {
            throw ValidationException::withMessages(['email' => 'Ingresá el celular o el correo.']);
        }

        return $this->current->run($organization, function (Organization $organization) use ($email, $phone, $roles, $invitedBy, $guardian, $name, $groupIds) {
            // Una sola invitación pendiente por persona: la nueva reemplaza a las anteriores.
            Invitation::query()
                ->where(fn ($query) => $phone ? $query->where('phone', $phone) : $query->where('email', $email))
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->each(fn (Invitation $old) => $old->update(['revoked_at' => now()]));

            $token = Invitation::newToken();

            $invitation = Invitation::query()->create([
                'organization_id' => $organization->id,
                'email' => $email,
                'phone' => $phone,
                'name' => filled($name) ? trim($name) : null,
                'roles' => $roles,
                'group_ids' => $groupIds === [] ? null : array_values(array_map('intval', $groupIds)),
                'guardian_id' => $guardian?->id,
                'token_hash' => Invitation::hashToken($token),
                'invited_by' => $invitedBy?->id,
                'expires_at' => now()->addDays(Invitation::VALID_DAYS),
            ]);

            if ($email !== null) {
                Mail::to($email)->queue(new InvitationMail($invitation, $token));
            }

            return [$invitation, $token];
        });
    }

    /**
     * Token nuevo y vencimiento renovado para una invitación pendiente o vencida.
     */
    public function resend(Invitation $invitation): string
    {
        [, $token] = $this->handle(
            $invitation->organization,
            $invitation->email,
            $invitation->roles,
            $invitation->invitedBy,
            $invitation->guardian,
            $invitation->name,
            $invitation->group_ids ?? [],
            $invitation->phone,
        );

        return $token;
    }

    /**
     * Invitación como tutor para un tutor cargado (panel o importación).
     *
     * @return array{0: Invitation, 1: string}
     */
    public function forGuardian(Guardian $guardian, ?User $invitedBy = null): array
    {
        $phone = Phone::mobile($guardian->phone);

        if ($phone === null && blank($guardian->email)) {
            throw ValidationException::withMessages(['email' => 'El tutor no tiene celular ni correo.']);
        }

        // Con correo se le manda por email; si solo tiene celular, se comparte por WhatsApp.
        return $this->handle(
            $guardian->organization,
            $guardian->email,
            [['role' => OrganizationRole::Guardian->value]],
            $invitedBy,
            $guardian,
            $guardian->full_name,
            phone: blank($guardian->email) ? $phone : null,
        );
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
