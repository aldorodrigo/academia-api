<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Push\FcmPushSender;
use App\Support\Push\LogPushSender;
use App\Support\Push\PushChannel;
use App\Support\Push\PushSender;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Messaging;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);

        // Push por FCM si hay credenciales de Firebase; si no (desarrollo), al log.
        $this->app->bind(PushSender::class, fn ($app) => filled(config('firebase.projects.'.config('firebase.default').'.credentials'))
            ? new FcmPushSender($app->make(Messaging::class))
            : new LogPushSender);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Notification::extend('push', fn ($app) => $app->make(PushChannel::class));

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
