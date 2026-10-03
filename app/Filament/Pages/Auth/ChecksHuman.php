<?php

namespace App\Filament\Pages\Auth;

use App\Support\Verification\Captcha;
use App\Support\Verification\CodeGuard;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;

/**
 * Formularios del panel que mandan códigos: Turnstile (si está configurado), campo trampa y un
 * tiempo mínimo de llenado (los bots completan el formulario al instante).
 */
trait ChecksHuman
{
    /** Segundos mínimos entre que se abre el formulario y se envía. */
    public const MIN_SECONDS = 2;

    public ?int $formOpenedAt = null;

    public function bootedChecksHuman(): void
    {
        $this->formOpenedAt ??= now()->getTimestamp();
    }

    /**
     * @return list<Component>
     */
    protected function humanCheckComponents(): array
    {
        return [
            // Campo trampa: invisible para las personas.
            TextInput::make('website')
                ->hiddenLabel()
                ->extraAttributes(['style' => 'position:absolute;left:-10000px;', 'aria-hidden' => 'true'])
                ->extraInputAttributes(['tabindex' => '-1', 'autocomplete' => 'off']),
            ...(Captcha::enabled() ? [
                ViewField::make('captcha_token')
                    ->hiddenLabel()
                    ->view('filament.forms.turnstile')
                    ->visible(fn () => $this->needsHumanCheck()),
            ] : []),
        ];
    }

    /** El paso actual del formulario manda un código (ej. no el de "código y contraseña nueva"). */
    protected function needsHumanCheck(): bool
    {
        return true;
    }

    /** Campo donde se muestra el error. */
    protected function humanCheckField(): string
    {
        return 'data.login';
    }

    protected function ensureHuman(CodeGuard $guard): void
    {
        $tooFast = $this->formOpenedAt !== null && now()->getTimestamp() - $this->formOpenedAt < self::MIN_SECONDS;

        if ($tooFast) {
            throw ValidationException::withMessages([$this->humanCheckField() => 'Confirmá que no sos un robot.']);
        }

        try {
            $guard->ensureHuman($this->data['captcha_token'] ?? null, $this->data['website'] ?? null, $this->humanCheckField());
        } finally {
            // El token ya se usó (Cloudflare no acepta el mismo dos veces): el próximo envío necesita uno nuevo.
            if (Captcha::enabled()) {
                $this->data['captcha_token'] = null;
                $this->dispatch('turnstile-reset');
            }
        }
    }
}
