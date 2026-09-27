<?php

namespace App\Actions\Billing;

use App\Enums\AdjustmentType;
use App\Models\Charge;
use App\Models\ChargeAdjustment;
use App\Models\ChargeWaiver;
use App\Models\ClassSession;
use App\Models\Enrollment;
use Illuminate\Support\Facades\DB;

/**
 * Clase suspendida que no se cobra (temporadas por día de entrenamiento). Nunca
 * rehace lo emitido:
 *  - período sin cuota todavía: SeasonPeriods ya no cuenta ese día;
 *  - cuota impaga: ajuste "Clase suspendida dd/mm" en esa cuota;
 *  - cuota con pagos: ajuste en la próxima cuota impaga de la inscripción o, si todavía
 *    no existe, un descuento pendiente (ChargeWaiver) que se aplica al emitirla.
 * Idempotente por clase e inscripción.
 */
class WaiveSuspendedClass
{
    public function apply(ClassSession $session): void
    {
        DB::transaction(function () use ($session) {
            foreach ($session->trainingDaySeasons()->get() as $season) {
                $enrollments = $session->enrollmentsQuery()->where('season_id', $season->id)->get();

                foreach ($enrollments as $enrollment) {
                    $this->forEnrollment($session, $enrollment);
                }
            }
        });
    }

    /**
     * Deshace el descuento (al volver a programar o reprogramar la clase) en las cuotas
     * que siguen impagas y borra los pendientes. Lo ya pagado no se toca.
     */
    public function undo(ClassSession $session): void
    {
        DB::transaction(function () use ($session) {
            $adjustments = ChargeAdjustment::query()
                ->where('class_session_id', $session->id)
                ->with('charge')
                ->get();

            foreach ($adjustments as $adjustment) {
                $charge = $adjustment->charge;

                if ($charge === null || $charge->paidAmount() > 0) {
                    continue;
                }

                $adjustment->delete();
                self::recalculate($charge);
                ChargeWaiver::query()->where('class_session_id', $session->id)->where('applied_charge_id', $charge->id)->delete();
            }

            ChargeWaiver::query()->where('class_session_id', $session->id)->whereNull('applied_charge_id')->delete();
        });
    }

    /**
     * Aplica los descuentos pendientes del alumno a una cuota recién emitida.
     */
    public function applyPending(Charge $charge): void
    {
        $waivers = ChargeWaiver::query()
            ->where('student_id', $charge->student_id)
            ->whereNull('applied_charge_id')
            ->orderBy('id')
            ->get();

        foreach ($waivers as $waiver) {
            if ($this->adjust($charge, $waiver->amount, $waiver->label, $waiver->class_session_id)) {
                $waiver->update(['applied_charge_id' => $charge->id]);
            }
        }
    }

    private function forEnrollment(ClassSession $session, Enrollment $enrollment): void
    {
        $date = $session->date->toDateString();
        $label = 'Clase suspendida '.$session->date->format('d/m');

        $already = ChargeAdjustment::query()->where('class_session_id', $session->id)
            ->whereHas('charge', fn ($query) => $query->where('enrollment_id', $enrollment->id))
            ->exists()
            || ChargeWaiver::query()->where('class_session_id', $session->id)->where('enrollment_id', $enrollment->id)->exists();

        if ($already) {
            return;
        }

        $charge = Charge::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('season_id', $enrollment->season_id)
            ->notVoided()
            ->whereNotNull('unit_amount')
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->first();

        // Cuota todavía no emitida: SeasonPeriods ya no cuenta ese día.
        if ($charge === null) {
            return;
        }

        $amount = (int) $charge->unit_amount;

        if ($charge->paidAmount() === 0) {
            $this->adjust($charge, $amount, $label, $session->id);

            return;
        }

        $next = Charge::query()
            ->where('enrollment_id', $enrollment->id)
            ->notVoided()
            ->whereDate('period_start', '>', $charge->period_end->toDateString())
            ->orderBy('period_start')
            ->get()
            ->first(fn (Charge $candidate) => $candidate->paidAmount() === 0);

        $waiver = ChargeWaiver::query()->create([
            'organization_id' => $session->organization_id,
            'class_session_id' => $session->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'amount' => $amount,
            'label' => $label,
        ]);

        if ($next !== null && $this->adjust($next, $amount, $label, $session->id)) {
            $waiver->update(['applied_charge_id' => $next->id]);
        }
    }

    /**
     * Ajuste negativo (sin dejar la cuota en menos de cero) y nuevo monto final.
     */
    private function adjust(Charge $charge, int $amount, string $label, int $classSessionId): bool
    {
        $amount = min($amount, $charge->final_amount);

        if ($amount <= 0) {
            return false;
        }

        $charge->adjustments()->create([
            'organization_id' => $charge->organization_id,
            'type' => AdjustmentType::SuspendedClass,
            'label' => $label,
            'amount' => -$amount,
            'class_session_id' => $classSessionId,
        ]);
        self::recalculate($charge);

        return true;
    }

    public static function recalculate(Charge $charge): void
    {
        Charge::withSuspendedClassAdjustment(fn () => $charge->update([
            'final_amount' => max(0, $charge->base_amount + (int) $charge->adjustments()->sum('amount')),
        ]));
    }
}
