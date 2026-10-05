<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Auth\SendVerificationCode;
use App\Actions\Auth\VerifyCode;
use App\Models\User;
use App\Support\Phone;
use App\Support\Verification\TooManyCodes;
use App\Support\Vocabulary;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * "Confirmá tu número" (o tu correo): el código de 6 dígitos que llega por WhatsApp o por correo,
 * el mismo que en la app.
 */
class VerifyAccount extends EmailVerificationPrompt
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        parent::mount();

        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->byWhatsApp() ? 'Confirmá tu número' : 'Confirmá tu correo';
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->getTitle();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('code')
                    ->label('Código')
                    ->placeholder('123456')
                    ->required()
                    ->length(6)
                    ->numeric()
                    ->autocomplete('one-time-code')
                    ->autofocus()
                    ->validationMessages([
                        'required' => 'Ingresá el código que te mandamos.',
                        'size' => 'El código tiene 6 dígitos.',
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        $user = $this->user();
        $intro = $this->byWhatsApp()
            ? 'Te mandamos un código de 6 dígitos por WhatsApp al <strong>'.e(Phone::display($user->phone)).'</strong>.'
            : 'Te mandamos un código de 6 dígitos a <strong>'.e($user->email).'</strong>. Si no lo ves, revisá la carpeta de spam.';

        return $schema->components([
            Text::make(new HtmlString($intro)),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('verify')
                ->footer([
                    Actions::make([
                        Action::make('verify')->label('Confirmar')->submit('verify'),
                    ])->fullWidth(),
                ]),
            Text::make(new HtmlString('¿No te llegó? '.$this->resendNotificationAction->toHtml())),
            ...($this->byWhatsApp() && filled($user->email)
                ? [Text::make(new HtmlString($this->resendByMailAction->toHtml()))]
                : []),
        ]);
    }

    public function verify(VerifyCode $verify): void
    {
        $data = $this->form->getState();

        $verify->handle($this->user(), (string) $data['code'], field: 'data.code');

        $this->redirect(Filament::getUrl());
    }

    /**
     * Reenviar: los límites los pone `CodeGuard` (los mismos que en la app).
     */
    public function resendNotificationAction(): Action
    {
        return Action::make('resendNotification')
            ->link()
            ->size('sm')
            ->label('Reenviar código.')
            ->action(function (): void {
                $this->send();
            });
    }

    public function resendByMailAction(): Action
    {
        return Action::make('resendByMail')
            ->link()
            ->size('sm')
            ->label('Mandámelo por correo.')
            ->action(function (): void {
                $this->send('mail');
            });
    }

    protected function sendEmailVerificationNotification(MustVerifyEmail $user): void
    {
        $this->send();
    }

    private function send(?string $channel = null): void
    {
        try {
            app(SendVerificationCode::class)->handle($this->user(), SendVerificationCode::VERIFY, $channel);
        } catch (TooManyCodes $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Te mandamos un código nuevo.')->success()->send();
    }

    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        return Notification::make()
            ->title('Esperá '.Vocabulary::count($exception->secondsUntilAvailable, 'segundo', 'segundos').' para pedir otro código.')
            ->danger();
    }

    private function user(): User
    {
        /** @var User */
        return Filament::auth()->user();
    }

    private function byWhatsApp(): bool
    {
        return filled($this->user()->phone);
    }
}
