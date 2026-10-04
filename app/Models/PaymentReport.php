<?php

namespace App\Models;

use App\Enums\PaymentReportStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Pago por transferencia informado por el tutor con su comprobante. Queda
 * pendiente hasta que alguien con "Validar comprobantes" lo aprueba (se registra
 * el pago con su recibo) o lo rechaza con motivo (business-logic.md regla 13).
 */
#[Fillable(['organization_id', 'family_id', 'user_id', 'money_account_id', 'amount', 'paid_on', 'reference', 'notes', 'charge_ids', 'proof_path', 'proof_name', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason', 'payment_id'])]
class PaymentReport extends Model
{
    use BelongsToOrganization, LogsActivity;

    protected $attributes = ['status' => 'pendiente', 'charge_ids' => '[]'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_on' => 'date',
            'charge_ids' => 'array',
            'status' => PaymentReportStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amount', 'paid_on', 'status', 'rejection_reason', 'payment_id'])
            ->logOnlyDirty()
            ->useLogName('billing');
    }

    public function isPending(): bool
    {
        return $this->status === PaymentReportStatus::Pending;
    }

    /**
     * Las cuotas elegidas, en el orden en que vencen.
     *
     * @return Collection<int, Charge>
     */
    public function charges(): Collection
    {
        return Charge::query()->withoutGlobalScopes()
            ->where('organization_id', $this->organization_id)
            ->whereIn('id', $this->charge_ids ?? [])
            ->with(['student', 'allocations.payment', 'organization'])
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', PaymentReportStatus::Pending);
    }

    /**
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Quien lo informó.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MoneyAccount, $this>
     */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * El pago que se registró al aprobarlo.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
