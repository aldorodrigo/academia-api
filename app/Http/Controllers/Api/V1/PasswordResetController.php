<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\ResetPasswordWithCode;
use App\Http\Controllers\Controller;
use App\Support\Verification\CodeGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password;

/**
 * "Olvidé mi contraseña": código por WhatsApp o correo y contraseña nueva. No revela si hay una cuenta.
 */
class PasswordResetController extends Controller
{
    public function forgot(Request $request, ResetPasswordWithCode $reset, CodeGuard $guard): Response
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'captcha_token' => ['nullable', 'string'],
            'website' => ['nullable'],
        ], ['login.required' => 'Ingresá tu celular o tu correo.']);

        $guard->ensureHuman($data['captcha_token'] ?? null, $data['website'] ?? null);

        $reset->request($data['login']);

        return response()->noContent();
    }

    public function reset(Request $request, ResetPasswordWithCode $reset): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'device_name' => ['required', 'string', 'max:255'],
        ], ['code.required' => 'Ingresá el código.']);

        $user = $reset->reset($data['login'], $data['code'], $data['password']);

        return response()->json(
            ['token' => $user->createToken($data['device_name'])->plainTextToken],
            Response::HTTP_CREATED,
        );
    }
}
