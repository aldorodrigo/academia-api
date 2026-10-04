<?php

namespace App\Filament\Support;

use App\Actions\Billing\UnwaiveCharge;
use App\Actions\Billing\WaiveCharges;
use App\Models\Charge;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * "Condonar" (Cargos y ficha del jugador → Cuenta): perdona lo que falta pagar, con motivo.
 * Solo con el permiso "Condonar deudas" (admin siempre; tesorero y presidente por defecto).
 */
class WaiveChargeAction
{
    private const DESCRIPTION = 'Se perdona lo que falta pagar: deja de sumar en la cuenta, en Saldos y en Morosos. '
        .'Lo ya pagado sigue siendo ingreso. Queda registrado quién, cuándo y por qué, y se puede deshacer.';

    public static function make(): Action
    {
        return Action::make('waive')
            ->label('Condonar')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('warning')
            ->visible(fn (Charge $record) => WaiveCharges::allows(auth()->user()) && WaiveCharges::canWaive($record))
            ->modalHeading(fn (Charge $record) => 'Condonar '.Money::pyg($record->pendingAmount())->format())
            ->modalDescription(self::DESCRIPTION)
            ->schema([self::reason()])
            ->modalSubmitActionLabel('Condonar')
            ->action(fn (Charge $record, array $data, Action $action) => self::run(new Collection([$record]), $data['reason'], $action));
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('waive')
            ->label('Condonar')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('warning')
            ->visible(fn () => WaiveCharges::allows(auth()->user()))
            ->modalDescription(self::DESCRIPTION.' Las anuladas o pagadas se saltean.')
            ->schema([self::reason()])
            ->modalSubmitActionLabel('Condonar')
            ->action(fn (Collection $records, array $data, BulkAction $action) => self::run(
                $records->load(['allocations.payment', 'organization'])->filter(fn (Charge $charge) => WaiveCharges::canWaive($charge)),
                $data['reason'],
                $action,
            ));
    }

    /**
     * Deshacer una condonación: la cuota vuelve a quedar pendiente (mismo permiso).
     */
    public static function undo(): Action
    {
        return Action::make('unwaive')
            ->label('Deshacer condonación')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Charge $record) => WaiveCharges::allows(auth()->user()) && $record->isWaived())
            ->modalHeading('Deshacer la condonación')
            ->modalDescription(fn (Charge $record) => 'La cuota vuelve a quedar pendiente por '.Money::pyg((int) $record->waived_amount)->format().'. Queda registrado quién lo deshizo y por qué.')
            ->schema([Textarea::make('reason')->label('Motivo')->required()->maxLength(255)->placeholder('Ej.: se condonó por error')])
            ->modalSubmitActionLabel('Deshacer')
            ->action(function (Charge $record, array $data, Action $action): void {
                try {
                    app(UnwaiveCharge::class)->handle($record, $data['reason'], auth()->user());
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(collect($exception->errors())->flatten()->first())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Condonación deshecha: la cuota vuelve a estar pendiente.')->send();
            });
    }

    private static function reason(): Textarea
    {
        return Textarea::make('reason')->label('Motivo')->required()->maxLength(255)
            ->placeholder('Ej.: dado de baja, lo decidió la comisión');
    }

    /**
     * @param  Collection<int, Charge>  $charges
     */
    private static function run(Collection $charges, string $reason, Action $action): void
    {
        try {
            $total = app(WaiveCharges::class)->handle($charges, $reason, auth()->user());
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title(collect($exception->errors())->flatten()->first())->send();
            $action->halt();

            return;
        }

        Notification::make()->success()
            ->title(($charges->count() === 1 ? 'Cuota condonada' : "{$charges->count()} cuotas condonadas").': '.Money::pyg($total)->format().'.')
            ->send();
    }
}
