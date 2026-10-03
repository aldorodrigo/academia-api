<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Invitation;
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

        abort_unless($this->invitation?->canBeAccepted(), 404, 'La invitación no es válida o ya venció.');
    }

    /**
     * Cuenta nueva: nombre y contraseña confirmada. Cuenta existente: su contraseña.
     */
    public function rules(): array
    {
        $newAccount = $this->invitation->existingUser() === null;

        return [
            'name' => $newAccount ? ['required', 'string', 'max:255'] : ['prohibited'],
            'password' => $newAccount ? ['required', 'confirmed', Password::min(8)] : ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
