<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChargeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChargeResource;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Charge;
use App\Models\Payment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estado de cuenta: consolidado de la familia (los alumnos a cargo del usuario) o de un hijo.
 */
class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $students = Student::query()->inChargeOf($request->user())->orderBy('birth_date')->get();

        return response()->json(['data' => $this->account($request, $students)]);
    }

    /**
     * 404 también para alumnos que existen pero no están a cargo del usuario.
     */
    public function show(Request $request, int $student): JsonResponse
    {
        $student = Student::query()->inChargeOf($request->user())->find($student);

        abort_if($student === null, 404, 'No encontramos a este alumno.');

        return response()->json(['data' => $this->account($request, new Collection([$student]))]);
    }

    /**
     * Cargos no anulados de los alumnos, pagos de sus familias y saldo a favor.
     *
     * @param  Collection<int, Student>  $students
     * @return array<string, mixed>
     */
    private function account(Request $request, Collection $students): array
    {
        $charges = Charge::query()
            ->notVoided()
            ->whereIn('student_id', $students->modelKeys())
            ->with(['student', 'feeConcept', 'group', 'season', 'adjustments', 'organization', 'allocations.payment'])
            ->orderByDesc('due_on')
            ->orderByDesc('id')
            ->get();

        $familyIds = $students->pluck('family_id')->filter()->unique()->values();
        $payments = Payment::query()
            ->whereIn('family_id', $familyIds)
            ->with(['allocations.charge.student'])
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        $credit = (int) $payments->sum(fn (Payment $payment) => $payment->credit());

        // Próximas: cuotas creadas por adelantado cuyo período no empezó.
        $totals = fn ($charges) => [
            'balance' => (int) $charges->sum(fn (Charge $charge) => $charge->pendingAmount()),
            'overdue' => (int) $charges->filter(fn (Charge $charge) => $charge->status() === ChargeStatus::Overdue)
                ->sum(fn (Charge $charge) => $charge->pendingAmount()),
            'due_now' => (int) $charges->reject(fn (Charge $charge) => $charge->isUpcoming())->sum(fn (Charge $charge) => $charge->pendingAmount()),
            'upcoming' => (int) $charges->filter(fn (Charge $charge) => $charge->isUpcoming())->sum(fn (Charge $charge) => $charge->pendingAmount()),
        ];
        $family = $totals($charges);
        $dueNow = max(0, $family['due_now'] - $credit);

        return [
            // El saldo a favor se descuenta primero de lo que hay que pagar ahora (nunca negativo).
            'balance' => max(0, $family['balance'] - $credit),
            'due_now' => $dueNow,
            'upcoming' => max(0, $family['upcoming'] - max(0, $credit - $family['due_now'])),
            'overdue' => $family['overdue'],
            'credit' => $credit,
            'students' => $students->map(fn (Student $student) => [
                'id' => $student->id,
                'full_name' => $student->full_name,
                ...$totals($charges->where('student_id', $student->id)),
            ])->values(),
            'charges' => ChargeResource::collection($charges)->toArray($request),
            'payments' => PaymentResource::collection($payments)->toArray($request),
        ];
    }
}
