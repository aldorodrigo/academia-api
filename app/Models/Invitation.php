<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Models\Concerns\BelongsToOrganization;
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
 * reenviar) y viaja por email/QR. Un solo uso; vence a los VALID_DAYS días.
 *
 * roles: [{role: 'tesorero', starts_on: '2026-01-01'|null, ends_on: '2027-12-31'|null}]
 */
#[Fillable(['organization_id', 'email', 'roles', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'accepted_user_id', 'revoked_at'])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    use BelongsToOrganization, LogsActivity;

    public const VALID_DAYS = 14;

    protected function casts(): array
    {
        return [
            'roles' => 'array',
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
