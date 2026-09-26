<?php

namespace App\Filament\Platform\Widgets;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Organizaciones activas', Organization::query()->active()->count()),
            Stat::make('Suspendidas', Organization::query()->whereNotNull('suspended_at')->count()),
            Stat::make('Usuarios', User::query()->count()),
            Stat::make('Invitaciones pendientes', Invitation::query()->withoutGlobalScopes()
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count()),
        ];
    }
}
