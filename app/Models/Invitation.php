<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Invitación para sumarse a una organización con ciertos roles.
 *
 * El token solo se guarda hasheado: el link se muestra una vez (al crear o
 * reenviar) y viaja por correo, WhatsApp o QR. Un solo uso; vence a los VALID_DAYS días.
 *
 * roles: [{role: 'tesorero', starts_on: '2026-01-01'|null, ends_on: '2027-12-31'|null}]
 *
 * guardian_id: invitación enviada a un tutor cargado; al aceptarla se vincula a la cuenta.
 */
#[Fillable(['organization_id', 'email', 'phone', 'name', 'roles', 'group_ids', 'guardian_id', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'accepted_user_id', 'revoked_at'])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    use BelongsToOrganization, LogsActivity;

    public const VALID_DAYS = 14;

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'group_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['email', 'roles', 'expires_at', 'accepted_at', 'accepted_user_id', 'revoked_at'])
            ->logOnlyDirty()
            ->useLogName('invitations');
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Busca por token entre todas las organizaciones (el endpoint es público).
     */
    public static function findByToken(string $token): ?self
    {
        return self::query()->withoutGlobalScopes()
            ->where('token_hash', self::hashToken($token))
            ->first();
    }

    public static function urlFor(string $token): string
    {
        return rtrim(config('app.frontend_url'), '/').'/invitacion/'.$token;
    }

    /**
     * La cuenta que ya tiene ese celular o ese correo verificado (primero el celular). Un dato sin verificar
     * no cuenta: se libera al crear la cuenta.
     */
    public function existingUser(): ?User
    {
        foreach (['phone' => $this->phone, 'email' => $this->email] as $column => $value) {
            if (filled($value) && ($user = User::query()->owning($column, $value)->first()) !== null) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Correo o celular al que va, para mostrar.
     */
    public function contact(): string
    {
        return Phone::display($this->phone) ?? (string) $this->email;
    }

    /**
     * Link de WhatsApp al celular invitado, con el texto y el link ya escritos.
     */
    public function whatsappUrl(string $token): ?string
    {
        return blank($this->phone) ? null : self::whatsappLink($this->phone, $this->whatsappText($token));
    }

    /**
     * Mensaje de WhatsApp con la invitación, escrito por quien invita (con el formato de WhatsApp).
     */
    public function whatsappText(string $token): string
    {
        $first = Str::before(trim((string) $this->name), ' ');
        $organization = $this->organization;

        return ($first === '' ? 'Hola' : "Hola {$first}").", te invito a sumarte a *{$organization->name}* en *Tuku*, "
            ."la app de cuotas, asistencia y avisos de clase.\n\n"
            ."Creá tu cuenta desde este link:\n".self::urlFor($token)."\n\n"
            .'Vence el '.$this->expires_at->timezone($organization->timezone)->format('d/m').' y sirve una sola vez.';
    }

    /**
     * `wa.me` con el texto escrito: al número o, sin número, para elegir a quién mandarlo.
     */
    public static function whatsappLink(?string $phone, string $text): string
    {
        return 'https://wa.me/'.(filled($phone) ? Phone::digits($phone) : '').'?text='.rawurlencode($text);
    }

    public function status(): InvitationStatus
    {
        return match (true) {
            $this->accepted_at !== null => InvitationStatus::Accepted,
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->expires_at->isPast() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    public function isPending(): bool
    {
        return $this->status() === InvitationStatus::Pending;
    }

    /**
     * Pendiente y de una organización no suspendida.
     */
    public function canBeAccepted(): bool
    {
        return $this->isPending() && ! $this->organization->isSuspended();
    }

    /**
     * @return list<string>
     */
    public function roleLabels(): array
    {
        return collect($this->roles)
            ->map(fn (array $role) => OrganizationRole::labelFor($role['role'], $this->organization))
            ->all();
    }

    /**
     * @return BelongsTo<Guardian, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }
}
