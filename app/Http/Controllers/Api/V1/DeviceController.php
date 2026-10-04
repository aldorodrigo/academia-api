<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Dispositivos para push. Un token es de un solo usuario: si otro lo registra
 * (cambió la cuenta en el mismo celular), pasa a ser suyo.
 */
class DeviceController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:android,ios,web'],
        ]);

        DeviceToken::query()->updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'], 'last_used_at' => now()],
        );

        return response()->noContent();
    }

    public function destroy(Request $request, string $token): Response
    {
        DeviceToken::query()->where('token', $token)->where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }
}
