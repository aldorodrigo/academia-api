<?php

namespace App\Actions\Billing;

use App\Filament\Support\Terms;
use App\Models\Charge;
use App\Models\Family;
use App\Models\Guardian;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\User;
use App\Notifications\PaymentReported;
use App\Support\Vocabulary;
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
     * El club registra la transferencia que la familia le mandó (la captura de WhatsApp): queda el comprobante y
     * quién la registró. Si quien la registra valida comprobantes (tesorero, admin), se aprueba en el acto y se
     * registra el pago con su recibo; si no (técnico), queda en revisión y avisa a quienes validan.
     *
     * @param  list<int>  $chargeIds
     */
    public function onBehalf(
        User $staff,
        Student $student,
        int $amount,
        CarbonImmutable $paidOn,
        UploadedFile $proof,
        array $chargeIds = [],
        ?int $moneyAccountId = null,
        ?Guardian $guardian = null,
        ?string $reference = null,
        ?string $notes = null,
    ): PaymentReport {
        $family = Family::ensureFor($student);
        $pending = app(RegisterPayment::class)->pendingCharges($family);
        $chargeIds = array_values(array_unique(array_map('intval', $chargeIds)));
        if (array_diff($chargeIds, $pending->modelKeys()) !== []) {
            throw ValidationException::withMessages(['charge_ids' => 'Hay cuotas que no son de esta familia o ya están pagadas.']);
        }
        $charges = $pending->whereIn('id', $chargeIds)->values();

        $this->ensureNotUnderReview($family->id, $charges, 'Ya hay una transferencia en revisión para «%s».');

        if ($guardian !== null && $guardian->family_id !== $family->id) {
            throw ValidationException::withMessages(['guardian_id' => 'Elegí un tutor de la familia.']);
        }
        if ($moneyAccountId !== null && ! PaymentReportAccess::clubTransferAccounts()->contains('id', $moneyAccountId)) {
            throw ValidationException::withMessages(['money_account_id' => 'Elegí una cuenta bancaria o billetera '.Vocabulary::of(Terms::organization()).'.']);
        }

        $organization = $student->organization;
        $report = PaymentReport::query()->create([
            'organization_id' => $organization->id,
            'family_id' => $family->id,
            'user_id' => $staff->id,
            'registered_by_staff' => true,
            'guardian_id' => $guardian?->id,
            'money_account_id' => $moneyAccountId,
            'amount' => $amount,
            'paid_on' => $paidOn->toDateString(),
            'reference' => filled($reference) ? trim($reference) : null,
            'notes' => filled($notes) ? trim($notes) : null,
            'charge_ids' => $charges->modelKeys(),
            'proof_path' => $proof->store("comprobantes-de-pago/{$organization->id}", 'local'),
            'proof_name' => Str::limit($proof->getClientOriginalName(), 200, ''),
        ]);

        if (! PaymentReportAccess::canReview($staff, $organization)) {
            Notification::send(PaymentReportAccess::reviewers($organization), new PaymentReported($report));

            return $report;
        }

        try {
            return app(ReviewPaymentReport::class)->approve($report, $staff);
        } catch (ValidationException $exception) {
            // Sin cuenta donde registrarlo: no queda un comprobante a medias (soft delete, con el archivo).
            $report->delete();

            throw $exception;
        }
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
            throw ValidationException::withMessages(['charge_ids' => 'No tenés '.Terms::plural('student', 'alumno').' a cargo con cuotas en '.Vocabulary::gendered(Terms::organization(), 'este', 'esta').' '.Terms::organization().'.']);
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
    private function ensureNotUnderReview(int $familyId, $charges, string $message = 'Ya informaste un pago para «%s»; esperá a que lo revisen.'): void
    {
        $underReview = PaymentReport::query()->pending()->where('family_id', $familyId)
            ->pluck('charge_ids')->flatten()->map(fn ($id) => (int) $id)->all();

        $repeated = $charges->first(fn (Charge $charge) => in_array($charge->id, $underReview, true));
        if ($repeated !== null) {
            throw ValidationException::withMessages([
                'charge_ids' => sprintf($message, $repeated->description),
            ]);
        }
    }
}
