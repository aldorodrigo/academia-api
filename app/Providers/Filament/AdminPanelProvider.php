<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RegisterAccount;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\VerifyAccount;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tenancy\EditOrganizationProfile;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Http\Middleware\SetCurrentOrganizationFromPanel;
use App\Models\Organization;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Se entra con el celular o el correo; la contraseña se recupera con un código.
            ->login(Login::class)
            ->passwordReset(RequestPasswordReset::class)
            // Alta autoservicio: cuenta → código (WhatsApp o correo) → "Tu club" (registro de la organización).
            ->registration(RegisterAccount::class)
            ->emailVerification(VerifyAccount::class)
            // La marca es Tuku (no APP_NAME, que en local dice "Academia").
            ->brandName('Tuku')
            ->brandLogo(asset('brand/tuku-logo.svg'))
            ->darkModeBrandLogo(asset('brand/tuku-logo-blanco.svg'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('brand/tuku-favicon.svg'))
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->tenant(Organization::class, slugAttribute: 'slug')
            ->tenantProfile(EditOrganizationProfile::class)
            ->tenantRegistration(RegisterOrganization::class)
            ->tenantMiddleware([
                SetCurrentOrganizationFromPanel::class,
            ], isPersistent: true)
            // Avisos de importaciones terminadas (corren en cola).
            ->databaseNotifications()
            ->navigationGroups(['Académico', 'Finanzas', 'Personas'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->userMenuItems([
                Action::make('platform')
                    ->label('Plataforma')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->url(fn () => url('/plataforma'))
                    ->visible(fn () => (bool) auth()->user()?->is_super_admin),
            ])
            ->plugins([
                // Los roles van con las personas (no en un grupo "Filament Shield").
                FilamentShieldPlugin::make()
                    ->navigationGroup('Personas')
                    ->navigationLabel('Roles y permisos'),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
