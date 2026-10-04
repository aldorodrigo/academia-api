<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\RegisterUser;
use App\Actions\Auth\SendVerificationCode;
use App\Actions\Auth\VerifyCode;
use App\Http\Controllers\Controller;
use App\Rules\ContactAvailable;
use App\Rules\MobilePhone;
use App\Support\Phone;
use App\Support\Verification\CodeGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Alta de cuenta (registro abierto) con el celular (y un correo opcional) o con el correo, y verificación con código.
 */
class RegisterController extends Controller
{
    public function store(Request $request, RegisterUser $register, CodeGuard $guard): JsonResponse
    {
        $request->merge([
            'phone' => filled($request->input('phone')) ? (Phone::mobile($request->input('phone')) ?? $request->input('phone')) : null,
            'email' => filled($request->input('email')) ? mb_strtolower(trim((string) $request->input('email'))) : null,
        ]);

        // Un celular o correo sin verificar no ocupa el dato. Con el celular, el correo es opcional: le llegan copias.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'string', new MobilePhone, new ContactAvailable('phone')],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255', new ContactAvailable('email')],
            'password' => ['required', 'confirmed', Password::min(8)],
            'device_name' => ['required', 'string', 'max:255'],
            'terms' => ['accepted'],
            'captcha_token' => ['nullable', 'string'],
            'website' => ['nullable'],
        ], [
            'phone.required_without' => 'Ingresá tu celular o tu correo.',
            'email.required_without' => 'Ingresá tu celular o tu correo.',
            'terms.accepted' => 'Tenés que aceptar los términos.',
        ]);

        $guard->ensureHuman($data['captcha_token'] ?? null, $data['website'] ?? null);
        $guard->ensureCanSend($data['phone'] ?? $data['email']);

        $user = $register->handle($data);

        return response()->json(
            ['token' => $user->createToken($data['device_name'])->plainTextToken],
            Response::HTTP_CREATED,
        );
    }

    public function verify(Request $request, VerifyCode $verify): Response
    {
        $data = $request->validate(['code' => ['required', 'string']], ['code.required' => 'Ingresá el código.']);

        $verify->handle($request->user(), $data['code']);

        return response()->noContent();
    }

    public function resend(Request $request, SendVerificationCode $send, CodeGuard $guard): Response
    {
        $data = $request->validate([
            'channel' => ['nullable', Rule::in(['whatsapp', 'mail'])],
            'captcha_token' => ['nullable', 'string'],
            'website' => ['nullable'],
        ]);

        $guard->ensureHuman($data['captcha_token'] ?? null, $data['website'] ?? null);

        $send->handle($request->user(), SendVerificationCode::VERIFY, $data['channel'] ?? null);

        return response()->noContent();
    }
}
