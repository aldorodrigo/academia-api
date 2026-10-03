<?php

namespace App\Filament\Platform\Widgets;

use App\Support\Verification\CodeGuard;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;

/**
 * Códigos de verificación de hoy (WhatsApp y correo), % verificado, gasto estimado y
 * "Pausar / reanudar WhatsApp" (también se pausa solo por el tope diario o por posible abuso).
 */
class VerificationCodes extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions, InteractsWithSchemas;

    protected string $view = 'filament.platform.widgets.verification-codes';

    protected int|string|array $columnSpan = 'full';

    public function toggleWhatsappAction(): Action
    {
        $paused = CodeGuard::paused() !== null;

        return Action::make('toggleWhatsapp')
            ->label($paused ? 'Reanudar WhatsApp' : 'Pausar WhatsApp')
            ->color($paused ? 'success' : 'danger')
            ->outlined(! $paused)
            ->requiresConfirmation()
            ->modalDescription($paused
                ? 'Los códigos vuelven a salir por WhatsApp.'
                : 'Los códigos van a salir por correo a quien lo tiene; los demás tienen que esperar.')
            ->action(function (CodeGuard $guard) use ($paused): void {
                $paused ? $guard->resume() : $guard->pause('Pausado a mano desde el panel de plataforma.');
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $stats = app(CodeGuard::class)->stats();

        return [
            ...$stats,
            'paused' => CodeGuard::paused(),
            'limit' => config('services.whatsapp.daily_limit'),
        ];
    }
}
