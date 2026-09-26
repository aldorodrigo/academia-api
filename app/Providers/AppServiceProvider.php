<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
