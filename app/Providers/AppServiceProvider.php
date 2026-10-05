<?php

namespace App\Providers;

use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\InboxChannel;
use App\Support\Push\FcmPushSender;
use App\Support\Push\LogPushSender;
use App\Support\Push\PushChannel;
use App\Support\Push\PushSender;
use App\Support\Roles\ShieldLabels;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\WhatsApp\CloudApiWhatsAppSender;
use App\Support\WhatsApp\LogWhatsAppSender;
use App\Support\WhatsApp\MailWhatsAppSender;
use App\Support\WhatsApp\WhatsAppSender;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Messaging;

class AppServiceProvider extends ServiceProvider
{
    /** Pedidos por minuto a una misma invitación (verla y aceptarla, con algún error de contraseña). */
    public const INVITATION_ATTEMPTS = 15;

    /** Pedidos por minuto a invitaciones desde una misma IP. */
    public const INVITATION_ATTEMPTS_PER_IP = 120;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);

        // "Roles y permisos": etiquetas con mayúscula solo al principio.
        $this->app->scoped('filament-shield', fn (): ShieldLabels => new ShieldLabels);

        // Push por FCM si hay credenciales de Firebase; si no (desarrollo), al log.
        $this->app->bind(PushSender::class, fn ($app) => filled(config('firebase.projects.'.config('firebase.default').'.credentials'))
            ? new FcmPushSender($app->make(Messaging::class))
            : new LogPushSender);

        // Códigos por WhatsApp (Cloud API de Meta) si hay token; si no (desarrollo), a Mailpit o al log.
        $this->app->bind(WhatsAppSender::class, fn () => filled(config('services.whatsapp.token'))
            ? new CloudApiWhatsAppSender(
                config('services.whatsapp.token'),
                (string) config('services.whatsapp.phone_number_id'),
                config('services.whatsapp.code_template'),
                config('services.whatsapp.template_language'),
                config('services.whatsapp.api_version'),
            )
            : (config('services.whatsapp.dev_driver') === 'mail' ? new MailWhatsAppSender : new LogWhatsAppSender));
    }

    /**
     * Fechas del panel en formato local (`05/10/2026 11:32`, no `oct. 5, 2026 11:32:17`) y las fechas con hora
     * en la zona horaria de la organización (las fechas solas y las horas de los horarios no se convierten).
     */
    private function configurePanelFormats(): void
    {
        $formats = fn (Table|Schema $component) => $component
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i')
            ->defaultTimeDisplayFormat('H:i');

        Table::configureUsing($formats);
        Schema::configureUsing($formats);

        $timezone = function (TextColumn|TextEntry $component): ?string {
            if (! $component->isDateTime()) {
                return null;
            }

            $tenant = Filament::getTenant();

            return $tenant instanceof Organization ? $tenant->timezone : app(CurrentOrganization::class)->get()?->timezone;
        };

        TextColumn::configureUsing(fn (TextColumn $column) => $column->timezone(fn () => $timezone($column)));
        TextEntry::configureUsing(fn (TextEntry $entry) => $entry->timezone(fn () => $timezone($entry)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Notification::extend('push', fn ($app) => $app->make(PushChannel::class));
        Notification::extend('inbox', fn ($app) => $app->make(InboxChannel::class));

        $this->configurePanelFormats();

        // Ver y aceptar una invitación: el límite es por invitación, para que varias familias en el mismo wifi
        // (una reunión de padres) puedan aceptar a la vez; el tope por IP, mucho más alto, frena a quien prueba
        // links al azar.
        RateLimiter::for('invitations', function (Request $request): array {
            $tooMany = fn () => response()->json(['message' => 'Demasiados intentos. Probá de nuevo en unos minutos.'], 429);

            return [
                Limit::perMinute(self::INVITATION_ATTEMPTS)
                    ->by('invitation:'.hash('sha256', (string) $request->route('token')))
                    ->response($tooMany),
                Limit::perMinute(self::INVITATION_ATTEMPTS_PER_IP)
                    ->by('invitation-ip:'.$request->ip())
                    ->response($tooMany),
            ];
        });

        // El super admin de la plataforma (is_super_admin) tiene acceso total;
        // el admin de la organización, acceso total dentro de la organización activa.
        Gate::before(function (User $user): ?bool {
            if ($user->is_super_admin) {
                return true;
            }

            $organization = app(CurrentOrganization::class)->get();

            return $organization !== null && $user->isOrganizationAdmin($organization) ? true : null;
        });
    }
}
