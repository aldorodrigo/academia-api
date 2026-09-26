<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptInvitationRequest extends FormRequest
{
    public ?Invitation $invitation = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->invitation = Invitation::findByToken((string) $this->route('token'));

        abort_unless($this->invitation?->isPending(), 404, 'La invitación no es válida o ya venció.');
    }

    /**
     * Cuenta nueva: nombre y contraseña confirmada. Cuenta existente: su contraseña.
     */
    public function rules(): array
    {
        $newAccount = ! User::query()->where('email', $this->invitation->email)->exists();

        return [
            'name' => $newAccount ? ['required', 'string', 'max:255'] : ['prohibited'],
            'password' => $newAccount ? ['required', 'confirmed', Password::min(8)] : ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
