<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Auth\RegisterUser;
use App\Rules\MobilePhone;
use App\Support\Phone;
use App\Support\Verification\CodeGuard;
use App\Support\Verification\TooManyCodes;
use Filament\Auth\Pages\Register;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Crear cuenta para registrar un club, con el celular (código por WhatsApp) o con el correo. Después
 * se pide el código y, con eso, "Tu club" (registro de la organización).
 */
class RegisterAccount extends Register
{
    use ChecksHuman;

    public function getTitle(): string|Htmlable
    {
        return 'Registrá tu club';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Registrá tu club';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Primero, tu cuenta. Después armamos el club juntos.';
    }

    public function form(Schema $schema): Schema
    {
        // Una cuenta sin verificar no ocupa el número ni el correo.
        $verified = fn (Unique $rule) => $rule->where(fn ($query) => $query->whereNotNull('phone_verified_at')->orWhereNotNull('email_verified_at'));

        return $schema->components([
            $this->getNameFormComponent()->label('Nombre y apellido'),
            Radio::make('via')
                ->hiddenLabel()
                ->options(['whatsapp' => 'Con mi celular (WhatsApp)', 'mail' => 'No tengo WhatsApp: con mi correo'])
                ->default('whatsapp')
                ->live()
                ->dehydrated(false),
            TextInput::make('phone')
                ->label('Celular (WhatsApp)')
                ->placeholder('0981 123 456')
                ->tel()
                ->required()
                ->helperText('Te mandamos un código por WhatsApp para confirmarlo.')
                ->rule(new MobilePhone)
                ->dehydrateStateUsing(fn (?string $state) => Phone::mobile($state) ?? $state)
                ->unique('users', 'phone', modifyRuleUsing: $verified)
                ->validationMessages(['unique' => 'Ya hay una cuenta con ese número. Ingresá con tu contraseña.'])
                ->visible(fn (Get $get) => $get('via') !== 'mail'),
            TextInput::make('email')
                ->label('Correo electrónico')
                ->email()
                ->required()
                ->maxLength(255)
                ->helperText('Te mandamos un código para confirmarlo.')
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? mb_strtolower(trim($state)) : null)
                ->unique('users', 'email', modifyRuleUsing: $verified)
                ->validationMessages(['unique' => 'Ya hay una cuenta con ese correo. Ingresá con tu contraseña.'])
                ->visible(fn (Get $get) => $get('via') === 'mail'),
            $this->getPasswordFormComponent()->label('Elegí una contraseña'),
            $this->getPasswordConfirmationFormComponent()->label('Repetí la contraseña'),
            Checkbox::make('terms')
                ->label('Acepto los términos de uso y la política de datos personales.')
                ->accepted()
                ->validationMessages(['accepted' => 'Tenés que aceptar los términos.'])
                ->dehydrated(false),
            ...$this->humanCheckComponents(),
            Text::make('¿Te invitaron a un club? Usá el link de la invitación.')->color('gray'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $guard = app(CodeGuard::class);
        $field = filled($data['phone'] ?? null) ? 'data.phone' : 'data.email';

        $this->ensureHuman($guard);

        try {
            $guard->ensureCanSend($data['phone'] ?? $data['email']);

            return app(RegisterUser::class)->handle($data);
        } catch (TooManyCodes $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }

    protected function humanCheckField(): string
    {
        return 'data.name';
    }

    /**
     * El código ya lo mandó RegisterUser.
     */
    protected function sendEmailVerificationNotification(Model $user): void {}
}
