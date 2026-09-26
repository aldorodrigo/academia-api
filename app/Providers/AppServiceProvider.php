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
        // El super admin de la plataforma (is_super_admin) tiene acceso total.
        Gate::before(fn (User $user): ?bool => $user->is_super_admin ? true : null);
    }
}
