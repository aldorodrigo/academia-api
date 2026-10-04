<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Auth\ResetPasswordWithCode;
use App\Support\Phone;
use App\Support\Verification\CodeGuard;
use App\Support\Verification\TooManyCodes;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password;

/**
 * "Olvidé mi contraseña" con código (el mismo que en la app): primero el celular o el correo, después
 * el código que llega por WhatsApp o por correo y la contraseña nueva. Al terminar, queda adentro.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    use ChecksHuman;

    public bool $codeSent = false;

    public function getTitle(): string|Htmlable
    {
        return 'Recuperar la contraseña';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Recuperar la contraseña';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('login')
                ->label('Celular o correo')
                ->placeholder('0981 123 456')
                ->required()
                ->disabled(fn () => $this->codeSent)
                ->dehydrated()
                ->autofocus(),
            ...$this->humanCheckComponents(),
            Text::make(fn () => Phone::looksLikeEmail((string) ($this->data['login'] ?? ''))
                ? 'Si hay una cuenta con ese correo, te mandamos un código. Si no lo ves, revisá la carpeta de spam.'
                : 'Si hay una cuenta con ese número, te mandamos un código por WhatsApp.')
                ->visible(fn () => $this->codeSent),
            TextInput::make('code')
                ->label('Código')
                ->placeholder('123456')
                ->required()
                ->length(6)
                ->numeric()
                ->autocomplete('one-time-code')
                ->visible(fn () => $this->codeSent),
            TextInput::make('password')
                ->label('Contraseña nueva')
                ->password()
                ->revealable()
                ->required()
                ->rule(Password::min(8))
                ->same('passwordConfirmation')
                ->validationMessages(['same' => 'Las contraseñas no coinciden.'])
                ->visible(fn () => $this->codeSent),
            TextInput::make('passwordConfirmation')
                ->label('Repetí la contraseña')
                ->password()
                ->required()
                ->dehydrated(false)
                ->visible(fn () => $this->codeSent),
        ]);
    }

    protected function needsHumanCheck(): bool
    {
        return ! $this->codeSent;
    }

    public function request(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();
        $reset = app(ResetPasswordWithCode::class);

        try {
            if (! $this->codeSent) {
                $this->ensureHuman(app(CodeGuard::class));
                $reset->request($data['login']);
                $this->codeSent = true;

                return;
            }

            $user = $reset->reset($data['login'], (string) $data['code'], $data['password'], 'data.code');
        } catch (TooManyCodes $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Filament::auth()->login($user);
        session()->regenerate();

        $this->redirect(Filament::getUrl());
    }

    protected function getRequestFormAction(): Action
    {
        return Action::make('request')
            ->label(fn () => $this->codeSent ? 'Cambiar contraseña' : 'Mandar código')
            ->submit('request');
    }
}
