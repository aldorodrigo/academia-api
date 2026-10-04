<?php

namespace App\Http\Controllers;

use App\Actions\Attendance\AnswerClassReminder;
use App\Notifications\ClassReminder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Sí, va" / "No va" del aviso de clase por correo. El link (firmado, vence al empezar la clase) abre una
 * página que pregunta; la respuesta se guarda con el botón de la página (POST), no al abrir el link: los
 * antivirus de correo abren los links solos.
 */
class ClassReminderPageController extends Controller
{
    public function show(Request $request, int $class, int $user, AnswerClassReminder $answer): Response
    {
        if (! $request->hasValidSignature()) {
            return self::expired();
        }

        [$session, , $students] = $answer->resolve($class, $user, (string) $request->query('students'));
        $going = $request->boolean('going');
        $reminder = new ClassReminder($session, $students, $session->organization->today(), $user);
        $link = fn (bool $going) => URL::temporarySignedRoute('class-reminder.show', $session->startsAt(), [
            'class' => $class,
            'user' => $user,
            'students' => $students->pluck('id')->implode(','),
            'going' => $going ? 1 : 0,
        ]);

        return response()->view('site.aviso', [
            'pose' => 'hola',
            'title' => 'Día de clase',
            'message' => $reminder->body,
            'actions' => [
                ['label' => 'Sí, va', 'url' => $link(true), 'primary' => $going],
                ['label' => 'No va', 'url' => $link(false), 'primary' => ! $going],
            ],
        ]);
    }

    public function store(Request $request, int $class, int $user, AnswerClassReminder $answer): Response
    {
        if (! $request->hasValidSignature()) {
            return self::expired();
        }

        $going = $request->boolean('going');

        try {
            $message = $answer->handle($class, $user, (string) $request->query('students'), $going);
        } catch (ValidationException $exception) {
            return response()->view('site.aviso', [
                'pose' => 'piensa',
                'title' => 'No se pudo guardar',
                'message' => $exception->validator->errors()->first(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->view('site.aviso', [
            'pose' => $going ? 'salta' : 'descansa',
            'title' => 'Respuesta guardada',
            'message' => $message,
        ]);
    }

    private static function expired(): Response
    {
        return response()->view('site.aviso', [
            'pose' => 'piensa',
            'title' => 'Este link venció',
            'message' => 'La clase ya empezó o el link no es válido. Si necesitás avisar algo, hacelo desde la app.',
        ], Response::HTTP_FORBIDDEN);
    }
}
