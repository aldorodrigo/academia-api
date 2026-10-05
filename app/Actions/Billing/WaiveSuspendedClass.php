<?php

namespace App\Actions\Billing;

use App\Enums\AdjustmentType;
use App\Models\Charge;
use App\Models\ChargeWaiver;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Clase suspendida que no se cobra (temporadas por día de entrenamiento). Un cargo emitido
 * nunca se modifica:
 *  - período sin cuota todavía: SeasonPeriods ya no cuenta ese día;
 *  - cuota impaga: se anula y se vuelve a emitir sin ese día;
 *  - cuota con pagos: queda un descuento pendiente (ChargeWaiver) que entra como ajuste al
 *    emitir la próxima cuota del alumno (si la próxima ya existe y está impaga, se reemite).
 * Se llama al pasar la clase a "suspendida sin cobrar" (apply) y al dejar de estarlo (undo),
 * siempre con la clase ya guardada en su estado nuevo.
 */
class WaiveSuspendedClass
{
    public function apply(ClassSession $session, ?User $by = null): void
    {
        DB::transaction(function () use ($session, $by) {
            foreach ($session->trainingDaySeasons()->get() as $season) {
                foreach ($session->enrollmentsQuery()->where('season_id', $season->id)->get() as $enrollment) {
                    $this->forEnrollment($session, $enrollment, $by);
                }
            }
        });
    }

    /**
     * Deshace el descuento (al volver a programar o reprogramar la clase): las cuotas impagas
     * se reemiten con ese día y se archivan los descuentos pendientes (soft delete). Lo ya pagado no se toca.
     */
    public function undo(ClassSession $session, ?User $by = null): void
    {
        DB::transaction(function () use ($session, $by) {
            // Descuentos que entraron en otra cuota (la cuota del día ya estaba pagada).
            $applied = ChargeWaiver::query()->where('class_session_id', $session->id)->whereNotNull('applied_charge_id')->get();
            foreach ($applied as $waiver) {
                $charge = Charge::query()->find($waiver->applied_charge_id);

                if ($charge !== null && ! $charge->isVoided() && $charge->paidAmount() === 0) {
                    $waiver->delete();
                    $this->reissue($charge, "Se vuelve a emitir: la clase del {$session->date->format('d/m')} se dio.", $by);
                }
            }
            ChargeWaiver::query()->where('class_session_id', $session->id)->whereNull('applied_charge_id')->delete();

            // Cuotas impagas del período de la clase: vuelven a contar ese día.
            foreach ($session->trainingDaySeasons()->get() as $season) {
                foreach ($session->enrollmentsQuery()->where('season_id', $season->id)->get() as $enrollment) {
                    $charge = $this->chargeCovering($session, $enrollment);

                    if ($charge !== null && $charge->paidAmount() === 0) {
                        $this->reissue($charge, "Se vuelve a emitir: la clase del {$session->date->format('d/m')} se da.", $by);
                    }
                }
            }
        });
    }

    /**
     * Ajustes de los descuentos pendientes del alumno, para sumarlos al emitir una cuota.
     *
     * @return array{adjustments: list<array<string, mixed>>, waivers: list<int>}
     */
    public function pendingFor(int $studentId, int $base): array
    {
        $adjustments = [];
        $waivers = [];
        $left = $base;

        $pending = ChargeWaiver::query()->where('student_id', $studentId)->whereNull('applied_charge_id')->orderBy('id')->get();

        foreach ($pending as $waiver) {
            $amount = min($waiver->amount, $left);
            if ($amount <= 0) {
                break;
            }
            $adjustments[] = [
                'type' => AdjustmentType::SuspendedClass,
                'label' => $waiver->label,
                'amount' => -$amount,
                'class_session_id' => $waiver->class_session_id,
            ];
            $waivers[] = $waiver->id;
            $left -= $amount;
        }

        return ['adjustments' => $adjustments, 'waivers' => $waivers];
    }

    private function forEnrollment(ClassSession $session, Enrollment $enrollment, ?User $by): void
    {
        $charge = $this->chargeCovering($session, $enrollment);

        // Cuota todavía no emitida: SeasonPeriods ya no cuenta ese día.
        if ($charge === null || $this->alreadyHandled($session, $enrollment)) {
            return;
        }

        if ($charge->paidAmount() === 0) {
            $this->reissue($charge, self::reissueReason($session), $by);

            return;
        }

        ChargeWaiver::query()->create([
            'organization_id' => $session->organization_id,
            'class_session_id' => $session->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'amount' => (int) $charge->unit_amount,
            'label' => self::label($session),
        ]);

        // La próxima cuota ya emitida e impaga se reemite con el descuento.
        $next = Charge::query()
            ->where('enrollment_id', $enrollment->id)
            ->notVoided()
            ->whereDate('period_start', '>', $charge->period_end->toDateString())
            ->orderBy('period_start')
            ->get()
            ->first(fn (Charge $candidate) => $candidate->paidAmount() === 0);

        if ($next !== null) {
            $this->reissue($next, 'Se vuelve a emitir con el descuento de la '.mb_strtolower(self::label($session)).'.', $by);
        }
    }

    /**
     * Ya se descontó esta suspensión: hay un descuento pendiente o una cuota que se anuló para
     * reemitirla después de suspender la clase.
     */
    private function alreadyHandled(ClassSession $session, Enrollment $enrollment): bool
    {
        return ChargeWaiver::query()->where('class_session_id', $session->id)->where('enrollment_id', $enrollment->id)->exists()
            || Charge::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereNotNull('voided_at')
                ->where('voided_at', '>=', $session->updated_at)
                ->where('void_reason', self::reissueReason($session))
                ->exists();
    }

    private function chargeCovering(ClassSession $session, Enrollment $enrollment): ?Charge
    {
        $date = $session->date->toDateString();

        return Charge::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('season_id', $enrollment->season_id)
            ->notVoided()
            ->whereNotNull('unit_amount')
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->first();
    }

    private function reissue(Charge $charge, string $reason, ?User $by): void
    {
        // Se resuelve acá: VoidCharge depende de IssueSeasonCharges, que depende de esta clase.
        app(VoidCharge::class)->handle($charge, $reason, $by ?? auth()->user(), reissue: true);
    }

    /**
     * "Se vuelve a emitir sin la clase suspendida 28/09."
     */
    private static function reissueReason(ClassSession $session): string
    {
        return 'Se vuelve a emitir sin la '.mb_strtolower(self::label($session)).'.';
    }

    /**
     * "Clase suspendida 28/09".
     */
    public static function label(ClassSession $session): string
    {
        return 'Clase suspendida '.$session->date->format('d/m');
    }
}
