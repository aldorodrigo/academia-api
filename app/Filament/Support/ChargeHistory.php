<?php

namespace App\Filament\Support;

use App\Models\Charge;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Historial de un cargo para el panel: emisión (y a qué cuota anulada reemplaza), pagos y
 * saldo a favor aplicados, anulación con motivo y quién la hizo, y la cuota que la reemplazó.
 */
class ChargeHistory
{
    /**
     * @return Collection<int, array{at: CarbonInterface, text: string, by: ?string}>
     */
    public static function for(Charge $charge): Collection
    {
        $charge->loadMissing(['allocations.payment']);
        $names = fn (?int $id) => $id === null ? null : User::query()->find($id)?->name;
        $money = fn (int $amount) => Money::pyg($amount)->format();
        $entries = collect();

        $replaced = self::sibling($charge, before: true);
        $entries->push([
            'at' => $charge->created_at,
            'text' => 'Emitida por '.$money($charge->final_amount)
                .($replaced ? ' (reemplaza a la cuota anulada el '.$replaced->voided_at->format('d/m/Y').')' : ''),
            'by' => $names($charge->created_by),
        ]);

        foreach ($charge->allocations as $allocation) {
            /** @var PaymentAllocation $allocation */
            $payment = $allocation->payment;
            $entries->push([
                'at' => $allocation->created_at,
                'text' => ($allocation->from_credit ? 'Saldo a favor aplicado' : "Pago, recibo N° {$payment->receiptLabel()}")
                    .' · '.$money($allocation->covered())
                    .($payment->isVoided() ? ' (pago anulado: '.$payment->void_reason.')' : ''),
                'by' => $names($payment->created_by),
            ]);
        }

        if ($charge->isVoided()) {
            $entries->push([
                'at' => $charge->voided_at,
                'text' => "Anulada: {$charge->void_reason}",
                'by' => $names($charge->voided_by),
            ]);

            if ($next = self::sibling($charge, before: false)) {
                $entries->push([
                    'at' => $next->created_at,
                    'text' => 'Reemplazada por una nueva cuota de '.$money($next->final_amount),
                    'by' => $names($next->created_by),
                ]);
            }
        }

        // Otros cambios registrados en la auditoría (por ejemplo, la descripción).
        Activity::query()
            ->where('log_name', 'billing')
            ->where('subject_type', $charge->getMorphClass())
            ->where('subject_id', $charge->id)
            ->where('description', 'updated')
            ->get()
            ->reject(fn (Activity $activity) => collect($activity->attribute_changes?->get('attributes') ?? [])->has('voided_at'))
            ->each(fn (Activity $activity) => $entries->push([
                'at' => $activity->created_at,
                'text' => 'Modificada',
                'by' => $activity->causer?->name,
            ]));

        return $entries->sortBy(fn (array $entry) => $entry['at']->getTimestamp())->values();
    }

    /**
     * La cuota anulada que esta reemplaza (before) o la que la reemplazó: misma inscripción y período.
     */
    private static function sibling(Charge $charge, bool $before): ?Charge
    {
        if ($charge->enrollment_id === null || $charge->period_start === null) {
            return null;
        }

        $query = Charge::query()
            ->where('enrollment_id', $charge->enrollment_id)
            ->where('fee_concept_id', $charge->fee_concept_id)
            ->whereDate('period_start', $charge->period_start->toDateString())
            ->whereKeyNot($charge->id);

        return $before
            ? $query->whereNotNull('voided_at')->where('id', '<', $charge->id)->orderByDesc('id')->first()
            : $query->where('id', '>', $charge->id)->orderBy('id')->first();
    }
}
