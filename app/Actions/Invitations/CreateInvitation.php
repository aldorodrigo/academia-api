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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Crea (o renueva) una invitación a un celular, a un correo o a los dos; una sola abierta por persona. Con
 * correo, la envía por email; con celular, quien invita la comparte por WhatsApp (link `wa.me` al número, sin
 * costo). Con los dos, le llega por los dos lados.
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
        $email = filled($email) ? mb_strtolower(trim($email)) : null;

        if ($phone === null && $email === null) {
            throw ValidationException::withMessages(['email' => 'Ingresá el celular o el correo.']);
        }

        return $this->current->run($organization, function (Organization $organization) use ($email, $phone, $roles, $invitedBy, $guardian, $name, $groupIds) {
            // Una sola invitación abierta por persona: se reutiliza la pendiente (o vencida) con los datos nuevos y
            // un link nuevo, en lugar de sumar otra. Si quedaran otras abiertas, se revocan.
            $open = $this->openFor($phone, $email)->orderByDesc('id')->get();

            $invitation = $open->shift() ?? new Invitation(['organization_id' => $organization->id]);
            $this->revoke($open);

            $invitation->fill([
                'email' => $email,
                'phone' => $phone,
                'name' => filled($name) ? trim($name) : null,
                'roles' => $roles,
                'group_ids' => $groupIds === [] ? null : array_values(array_map('intval', $groupIds)),
                'guardian_id' => $guardian?->id,
                'invited_by' => $invitedBy?->id,
            ]);

            return [$invitation, $this->issue($invitation)];
        });
    }

    /**
     * Reenviar: la misma invitación (pendiente o vencida) con un token nuevo y el vencimiento renovado. El link
     * anterior deja de servir (el token solo se guarda hasheado, así que no se puede volver a mostrar).
     */
    public function resend(Invitation $invitation): string
    {
        return $this->current->run($invitation->organization, function () use ($invitation) {
            $this->revoke($this->openFor($invitation->phone, $invitation->email)->whereKeyNot($invitation->id)->get());

            return $this->issue($invitation);
        });
    }

    /**
     * Token nuevo y VALID_DAYS días más; con correo, se la manda por email. Devuelve el token en claro.
     */
    private function issue(Invitation $invitation): string
    {
        $token = Invitation::newToken();

        $invitation->fill([
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(Invitation::VALID_DAYS),
        ])->save();

        if (filled($invitation->email)) {
            Mail::to($invitation->email)->queue(new InvitationMail($invitation, $token));
        }

        return $token;
    }

    /**
     * Invitaciones sin aceptar ni revocar (pendientes o vencidas) a ese celular o a ese correo.
     *
     * @return Builder<Invitation>
     */
    private function openFor(?string $phone, ?string $email): Builder
    {
        return Invitation::query()
            ->where(fn (Builder $query) => $query
                ->when($phone, fn (Builder $query) => $query->orWhere('phone', $phone))
                ->when($email, fn (Builder $query) => $query->orWhere('email', $email)))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at');
    }

    /**
     * @param  iterable<Invitation>  $invitations
     */
    private function revoke(iterable $invitations): void
    {
        foreach ($invitations as $invitation) {
            $invitation->update(['revoked_at' => now()]);
        }
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

        // Con correo se le manda por email; con celular, se comparte por WhatsApp (con los dos, por los dos).
        return $this->handle(
            $guardian->organization,
            $guardian->email,
            [['role' => OrganizationRole::Guardian->value]],
            $invitedBy,
            $guardian,
            $guardian->full_name,
            phone: $phone,
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
