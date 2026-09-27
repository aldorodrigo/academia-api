<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\RespondToClass;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Notifications\ClassReminder;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Botones "Sí, va" / "No va" del push: link firmado (sin token) que vence al empezar la clase.
 */
class ClassResponseController extends Controller
{
    public function __invoke(Request $request, int $class, int $user, CurrentOrganization $current, RespondToClass $respond): JsonResponse
    {
        $session = ClassSession::query()->withoutGlobalScopes()->with('organization')->find($class);
        $user = User::query()->find($user);
        abort_if($session === null || $user === null, 404, 'No encontramos esta clase.');

        return $current->run($session->organization, function () use ($request, $session, $user, $respond) {
            $going = $request->boolean('going');
            $ids = array_filter(array_map('intval', explode(',', (string) $request->query('students'))));
            $students = Student::query()->inChargeOf($user)->whereKey($ids)->get();
            abort_if($students->isEmpty(), 404, 'No encontramos a este alumno.');

            foreach ($students as $student) {
                $respond->handle($session, $student, $going, $user);
            }

            $names = ClassReminder::names($students->pluck('first_name')->all());
            $verb = $going
                ? ($students->count() > 1 ? 'van' : 'va')
                : ($students->count() > 1 ? 'no van' : 'no va');

            return response()->json(['data' => ['message' => "Listo: avisaste que {$names} {$verb}."]]);
        });
    }
}
