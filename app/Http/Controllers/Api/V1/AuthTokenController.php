<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthTokenController extends Controller
{
    /** Contraseñas incorrectas seguidas que bloquean la cuenta 15 minutos. */
    public const MAX_FAILED_LOGINS = 10;

    /**
     * Emite un token Sanctum para la app móvil. Se entra con el celular o el correo.
     */
    public function store(Request $request): JsonResponse
    {
        // Compatibilidad con la versión anterior de la app (`email`).
        if (! $request->filled('login') && $request->filled('email')) {
            $request->merge(['login' => $request->input('email')]);
        }

        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ], ['login.required' => 'Ingresá tu celular o tu correo.']);

        $user = User::findByLogin($data['login']);
        $lockKey = 'login-failed:'.($user?->id ?? sha1(mb_strtolower(trim($data['login']))));

        if (RateLimiter::tooManyAttempts($lockKey, self::MAX_FAILED_LOGINS)) {
            return response()->json(['message' => 'Demasiados intentos. Probá de nuevo en unos minutos.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($lockKey, 15 * 60);

            throw ValidationException::withMessages([
                'login' => 'Estas credenciales no coinciden con nuestros registros.',
            ]);
        }

        RateLimiter::clear($lockKey);

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
        ], Response::HTTP_CREATED);
    }

    /**
     * Revoca el token usado en la petición.
     */
    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
