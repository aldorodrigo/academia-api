<?php

namespace App\Actions\Lessons;

use App\Actions\Billing\RegisterPayment;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Charge;
use App\Models\ClassPack;
use App\Models\Family;
use App\Models\FeeConcept;
use App\Models\LessonProfile;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * El profesor cobra desde la app: entra en la cuenta de su perfil y se imputa al cargo de la
 * reserva o del paquete; sin uno de esos, a sus clases pendientes con el alumno. Si no hay
 * cargo todavía (cobro antes de la clase), queda como saldo a favor de la familia.
 */
class CollectLessonPayment
{
    public function __construct(private RegisterPayment $register) {}

    public function handle(
        LessonProfile $profile,
        Student $student,
        int $amount,
        PaymentMethod $method,
        User $by,
        ?Booking $booking = null,
        ?ClassPack $pack = null,
    ): Payment {
        $account = $profile->account();

        if ($account === null) {
            throw ValidationException::withMessages(['amount' => 'No hay una cuenta para registrar el cobro. Pedile al tesorero que cree una.']);
        }

        $family = Family::ensureFor($student);
        $charges = match (true) {
            $booking !== null => collect([$booking->charge])->filter(),
            $pack !== null => collect([$pack->charge])->filter(),
            default => Charge::query()
                ->where('student_id', $student->id)
                ->where('fee_concept_id', FeeConcept::privateLesson($profile->organization)->id)
                ->whereNull('voided_at')
                ->where(fn ($query) => $query
                    ->whereHas('bookings', fn ($bookings) => $bookings->where('user_id', $profile->user_id))
                    ->orWhereHas('classPacks', fn ($packs) => $packs->where('user_id', $profile->user_id)))
                ->orderBy('due_on')
                ->orderBy('id')
                ->get(),
        };

        // Lo que se imputa a cada cargo del profesor; el resto queda a favor.
        $allocations = [];
        $left = $amount;

        foreach ($charges as $charge) {
            $pending = $charge->isVoided() ? 0 : $charge->pendingAmount();
            if ($left <= 0 || $pending <= 0) {
                continue;
            }
            $allocations[$charge->id] = min($left, $pending);
            $left -= $allocations[$charge->id];
        }

        return $this->register->handle(
            family: $family,
            account: $account,
            amount: $amount,
            method: $method,
            receivedOn: $profile->organization->today(),
            by: $by,
            allocations: $allocations,
            notes: 'Cobro de clases particulares desde la app',
        );
    }
}
