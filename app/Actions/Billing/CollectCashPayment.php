<?php

namespace App\Actions\Billing;

use App\Enums\PaymentMethod;
use App\Models\Family;
use App\Models\Guardian;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Notifications\PaymentReceived;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Cobro en efectivo desde la app: un pago normal (`RegisterPayment`) con fecha de hoy que
 * entra en la caja personal de quien cobra, imputado a las cuotas elegidas que sigan
 * pendientes (o a las más viejas). Avisa a la familia con el recibo. Un reintento con el
 * mismo `request_id` devuelve el mismo pago.
 */
class CollectCashPayment
{
    public function __construct(private RegisterPayment $register) {}

    /**
     * @param  list<int>  $chargeIds
     */
    public function handle(
        User $by,
        Student $student,
        int $amount,
        array $chargeIds = [],
        ?Guardian $payer = null,
        ?string $notes = null,
        ?string $requestId = null,
    ): Payment {
        $organization = $student->organization;
        $key = $requestId === null ? null : "cash-collection:{$organization->id}:{$by->id}:{$requestId}";

        $collect = function () use ($by, $student, $amount, $chargeIds, $payer, $notes, $organization, $key): Payment {
            if ($key !== null && ($done = Cache::get($key)) !== null && ($payment = Payment::query()->find($done)) !== null) {
                return $payment;
            }

            $box = MoneyAccount::ensureCashBoxOf($by, $organization);
            if (! $box->is_active) {
                throw ValidationException::withMessages(['amount' => 'Tu caja está cerrada. Hablá con el tesorero.']);
            }

            $family = Family::ensureFor($student);
            if ($payer !== null && $payer->family_id !== $family->id) {
                throw ValidationException::withMessages(['guardian_id' => 'Elegí un tutor de la familia.']);
            }

            $today = $organization->today();
            $allocations = null;
            if ($chargeIds !== []) {
                $pending = $this->register->pendingCharges($family);
                if (array_diff($chargeIds, $pending->modelKeys()) !== []) {
                    throw ValidationException::withMessages(['charge_ids' => 'Hay cuotas que no son de esta familia o ya están pagadas.']);
                }
                $selected = $pending->whereIn('id', $chargeIds)->values();
                $allocations = collect($this->register->plan($selected, $amount, $today))->pluck('amount', 'charge_id')->all();
            }

            $payment = $this->register->handle(
                $family,
                $box,
                $amount,
                PaymentMethod::Cash,
                $today,
                $by,
                payer: $payer,
                allocations: $allocations,
                notes: collect(["Cobrado en efectivo por {$by->name} desde la app.", filled($notes) ? trim($notes) : null])->filter()->join(' '),
            );

            if ($key !== null) {
                Cache::put($key, $payment->id, now()->addDay());
            }

            Notification::send(self::familyUsers($family, $student), new PaymentReceived($payment, $by));

            return $payment;
        };

        // Dos pedidos con el mismo request_id a la vez: el segundo espera y devuelve el primero.
        return $key === null ? $collect() : Cache::lock("{$key}:lock", 30)->block(10, $collect);
    }

    /**
     * Tutores con cuenta y alumnos adultos de la familia (a quienes les llega el recibo).
     *
     * @return Collection<int, User>
     */
    public static function familyUsers(Family $family, ?Student $student = null): Collection
    {
        $guardians = Guardian::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('family_id', $family->id)->whereNotNull('user_id')->with('user')->get()->pluck('user');
        $students = Student::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('family_id', $family->id)->whereNotNull('user_id')->with('user')->get()->pluck('user');

        return $guardians->merge($students)->push($student?->user)->filter()->unique('id')->values();
    }
}
