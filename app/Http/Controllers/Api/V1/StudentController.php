<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StudentResource;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Mis hijos": alumnos a cargo del usuario en la organización activa.
 */
class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $students = $this->inChargeOf($request)
            ->with(['currentEnrollments.group.program', 'currentEnrollments.season.programs', 'media'])
            ->orderBy('birth_date')
            ->get();

        return response()->json([
            'data' => $students->map(fn (Student $student) => (new StudentResource($student))->toArray($request)),
        ]);
    }

    /**
     * 404 también para alumnos que existen pero no están a cargo del usuario.
     */
    public function show(Request $request, int $student): JsonResponse
    {
        $student = $this->inChargeOf($request)
            ->with([
                'currentEnrollments.group.program',
                'currentEnrollments.group.schedules.venue',
                'currentEnrollments.group.instructors',
                'currentEnrollments.season.programs',
                'guardians',
                'medicalRecord',
                'media',
            ])
            ->find($student);

        abort_if($student === null, 404, 'No encontramos a este alumno.');

        return response()->json([
            'data' => (new StudentResource($student))->detailed()->toArray($request),
        ]);
    }

    /**
     * @return Builder<Student>
     */
    private function inChargeOf(Request $request): Builder
    {
        return Student::query()->inChargeOf($request->user());
    }
}
