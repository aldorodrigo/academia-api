<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Quién tiene qué rol en la organización y hasta cuándo.
 *
 * Es la fuente de verdad; model_has_roles de spatie se sincroniza desde acá
 * (App\Support\Roles\RoleAssigner). Terminar una asignación no la borra:
 * queda como historial (ej. comisiones anteriores).
 */
#[Fillable(['organization_id', 'user_id', 'role_id', 'starts_on', 'ends_on', 'ended_at', 'assigned_by'])]
class RoleAssignment extends Model
{
    use BelongsToOrganization, LogsActivity;

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'ended_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->useLogName('roles');
    }

    /**
     * Vigentes en la fecha dada (por defecto, hoy en la zona de la organización activa).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query, ?string $date = null): void
    {
        $date ??= app(CurrentOrganization::class)->get()?->today()->toDateString() ?? today()->toDateString();

        $query->whereNull('ended_at')
            ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function roleEnum(): ?OrganizationRole
    {
        return OrganizationRole::tryFrom($this->role->name);
    }

    public function label(): string
    {
        return $this->roleEnum()?->label($this->organization) ?? Str::headline($this->role->name);
    }

    /**
     * "Tesorero · hasta 31/12/2027".
     */
    public function description(): string
    {
        return $this->ends_on === null
            ? $this->label()
            : $this->label().' · hasta '.$this->ends_on->format('d/m/Y');
    }
}
