<?php

namespace App\Actions\Billing;

use App\Models\Charge;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\User;
use App\Notifications\PaymentReported;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * El tutor informa una transferencia con el comprobante. La familia sale de las
 * cuotas elegidas (o de sus hijos, si paga a cuenta). Queda pendiente y avisa a
 * quienes validan.
 */
class SubmitPaymentReport
{
    /**
     * @param  list<int>  $chargeIds
     */
    public function handle(
        User $user,
        int $amount,
        CarbonImmutable $paidOn,
        UploadedFile $proof,
        array $chargeIds = [],
        ?int $moneyAccountId = null,
        ?string $reference = null,
        ?string $notes = null,
    ): PaymentReport {
        $students = Student::query()->inChargeOf($user)->get();
        $charges = $this->charges($students->modelKeys(), $chargeIds);
        $familyId = $this->familyId($students, $charges);

        $this->ensureNotUnderReview($familyId, $charges);

        if ($moneyAccountId !== null && ! PaymentReportAccess::transferAccounts()->contains('id', $moneyAccountId)) {
            throw ValidationException::withMessages(['money_account_id' => 'Elegí una de las cuentas para transferir.']);
        }

        $organizationId = $students->first()->organization_id;
        $report = PaymentReport::query()->create([
            'organization_id' => $organizationId,
            'family_id' => $familyId,
            'user_id' => $user->id,
            'money_account_id' => $moneyAccountId,
            'amount' => $amount,
            'paid_on' => $paidOn->toDateString(),
            'reference' => filled($reference) ? trim($reference) : null,
            'notes' => filled($notes) ? trim($notes) : null,
            'charge_ids' => $charges->modelKeys(),
            'proof_path' => $proof->store("comprobantes-de-pago/{$organizationId}", 'local'),
            'proof_name' => Str::limit($proof->getClientOriginalName(), 200, ''),
        ]);

        Notification::send(PaymentReportAccess::reviewers($report->organization), new PaymentReported($report));

        return $report;
    }

    /**
     * Cuotas pendientes de sus hijos (404 lógico: una ajena es un error de validación).
     *
     * @param  list<int>  $studentIds
     * @param  list<int>  $chargeIds
     * @return Collection<int, Charge>
     */
    private function charges(array $studentIds, array $chargeIds)
    {
        $chargeIds = array_values(array_unique(array_map('intval', $chargeIds)));
        $charges = Charge::query()
            ->notVoided()
            ->whereIn('id', $chargeIds)
            ->whereIn('student_id', $studentIds)
            ->with(['student', 'allocations.payment', 'organization'])
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();

        if ($charges->count() !== count($chargeIds)) {
            throw ValidationException::withMessages(['charge_ids' => 'Elegí cuotas de tus hijos.']);
        }

        $paid = $charges->first(fn (Charge $charge) => $charge->pendingAmount() === 0);
        if ($paid !== null) {
            throw ValidationException::withMessages(['charge_ids' => "«{$paid->description}» ya está pagada."]);
        }

        return $charges;
    }

    /**
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, Charge>  $charges
     */
    private function familyId($students, $charges): int
    {
        $families = ($charges->isNotEmpty() ? $charges->pluck('student') : $students)
            ->pluck('family_id')->filter()->unique()->values();

        if ($students->isEmpty() || $families->isEmpty()) {
            throw ValidationException::withMessages(['charge_ids' => 'No tenés alumnos a cargo con cuotas en este club.']);
        }

        if ($families->count() > 1) {
            throw ValidationException::withMessages(['charge_ids' => $charges->isEmpty()
                ? 'Elegí qué cuotas pagás.'
                : 'Elegí cuotas de una sola familia por comprobante.']);
        }

        return (int) $families->first();
    }

    /**
     * Una cuota no puede estar en dos comprobantes en revisión.
     *
     * @param  Collection<int, Charge>  $charges
     */
    private function ensureNotUnderReview(int $familyId, $charges): void
    {
        $underReview = PaymentReport::query()->pending()->where('family_id', $familyId)
            ->pluck('charge_ids')->flatten()->map(fn ($id) => (int) $id)->all();

        $repeated = $charges->first(fn (Charge $charge) => in_array($charge->id, $underReview, true));
        if ($repeated !== null) {
            throw ValidationException::withMessages([
                'charge_ids' => "Ya informaste un pago para «{$repeated->description}»; esperá a que lo revisen.",
            ]);
        }
    }
}
