<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\AnswerClassReminder;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Botones "Sí, va" / "No va" del push: link firmado (sin token) que vence al empezar la clase.
 */
class ClassResponseController extends Controller
{
    public function __invoke(Request $request, int $class, int $user, AnswerClassReminder $answer): JsonResponse
    {
        $message = $answer->handle($class, $user, (string) $request->query('students'), $request->boolean('going'));

        return response()->json(['data' => ['message' => $message]]);
    }
}
