<?php

namespace App\Actions\Lessons;

use App\Actions\Billing\ApplyCredit;
use App\Actions\Billing\IssueCharge;
use App\Actions\Billing\VoidCharge;
use App\Enums\BookingStatus;
use App\Enums\ClassPackStatus;
use App\Models\Booking;
use App\Models\Charge;
use App\Models\ClassPack;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\User;
use App\Notifications\PackLow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * El profesor marca si el alumno vino (desde el día de la clase hasta 3 días después; se corrige).
 *
 *  - Vino con paquete: usa una clase. Vino suelta: emite el cargo y aplica el saldo a favor.
 *  - No vino: no se cobra ni se descuenta. Corregir de "Vino" a "No vino" devuelve la clase o
 *    anula el cargo de la suelta (si todavía no tiene pagos).
 */
class MarkBooking
{
    public function __construct(
        private IssueCharge $issue,
        private VoidCharge $void,
        private ApplyCredit $applyCredit,
    ) {}

    public function handle(Booking $booking, bool $attended, User $by): Booking
    {
        $organization = $booking->organization;

        if (! $booking->isMarkable($organization->today())) {
            throw ValidationException::withMessages(['attended' => 'Esta clase no se puede marcar: se marca desde el día de la clase hasta 3 días después.']);
        }

        $target = $attended ? BookingStatus::Attended : BookingStatus::Absent;

        if ($booking->status === $target) {
            return $booking;
        }

        $wasAttended = $booking->status === BookingStatus::Attended;

        $pack = DB::transaction(function () use ($booking, $attended, $wasAttended, $by, $target) {
            $pack = $booking->class_pack_id === null
                ? null
                : ClassPack::query()->lockForUpdate()->find($booking->class_pack_id);

            if ($attended) {
                $pack !== null ? $this->usePack($pack) : $this->charge($booking, $by);
            } elseif ($wasAttended) {
                $pack !== null ? $this->returnToPack($pack) : $this->voidCharge($booking, $by);
            }

            $booking->update(['status' => $target, 'marked_at' => now()]);

            return $pack;
        });

        if ($attended && $booking->charge_id !== null) {
            $this->applyCredit->forFamily(Family::ensureFor($booking->student));
        }

        if ($attended && $pack !== null && $pack->remaining() === 1) {
            Notification::send(LessonAccess::recipients($booking->student), new PackLow($pack));
        }

        return $booking->refresh();
    }

    private function usePack(ClassPack $pack): void
    {
        $used = $pack->used + 1;
        $pack->update([
            'used' => $used,
            // Un paquete vencido que se usa en una clase anterior al vencimiento sigue vencido.
            ...($used >= $pack->classes && $pack->isActive() ? ['status' => ClassPackStatus::Finished] : []),
        ]);
    }

    private function returnToPack(ClassPack $pack): void
    {
        $pack->update([
            'used' => max(0, $pack->used - 1),
            ...($pack->status === ClassPackStatus::Finished ? ['status' => ClassPackStatus::Active] : []),
        ]);
    }

    private function charge(Booking $booking, User $by): void
    {
        if ($booking->charge_id !== null) {
            return;
        }

        $organization = $booking->organization;
        $teacher = $booking->teacher;
        $attempt = Charge::query()->where('unique_key', 'like', "bkg:{$booking->id}:%")->count() + 1;

        $charge = $this->issue->handle([
            'organization_id' => $organization->id,
            'student_id' => $booking->student_id,
            'fee_concept_id' => FeeConcept::privateLesson($organization)->id,
            'description' => "Clase particular {$booking->shortDate()}".($teacher ? " con {$teacher->name}" : ''),
            'base_amount' => $booking->price,
            'issued_on' => $organization->today()->toDateString(),
            'due_on' => $booking->date->toDateString(),
            'unique_key' => "bkg:{$booking->id}:{$attempt}",
            'created_by' => $by->id,
        ]);

        $booking->charge_id = $charge->id;
    }

    private function voidCharge(Booking $booking, User $by): void
    {
        $charge = $booking->charge;

        if ($charge === null) {
            return;
        }

        if ($charge->paidAmount() > 0) {
            throw ValidationException::withMessages(['attended' => 'Ya está cobrada: anulá el pago desde el panel.']);
        }

        $this->void->handle($charge, 'Marcado como ausente', $by);
        $booking->charge_id = null;
    }
}
