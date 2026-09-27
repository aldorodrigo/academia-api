<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChargeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChargeResource;
use App\Models\Charge;
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
     * Cargos no anulados de los alumnos (hasta el Sprint 4 todos están impagos).
     *
     * @param  Collection<int, Student>  $students
     * @return array<string, mixed>
     */
    private function account(Request $request, Collection $students): array
    {
        $charges = Charge::query()
            ->notVoided()
            ->whereIn('student_id', $students->modelKeys())
            ->with(['student', 'feeConcept', 'group', 'adjustments', 'organization'])
            ->orderByDesc('due_on')
            ->orderByDesc('id')
            ->get();

        $totals = fn ($charges) => [
            'balance' => (int) $charges->sum('final_amount'),
            'overdue' => (int) $charges->filter(fn (Charge $charge) => $charge->status() === ChargeStatus::Overdue)->sum('final_amount'),
        ];

        return [
            ...$totals($charges),
            'students' => $students->map(fn (Student $student) => [
                'id' => $student->id,
                'full_name' => $student->full_name,
                ...$totals($charges->where('student_id', $student->id)),
            ])->values(),
            'charges' => ChargeResource::collection($charges)->toArray($request),
        ];
    }
}
