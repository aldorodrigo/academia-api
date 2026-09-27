<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Actions\Treasury\TransferFunds;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\MoneyAccount;
use App\Models\Transfer;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageTransfers extends ManageRecords
{
    protected static string $resource = TransferResource::class;

    protected function getHeaderActions(): array
    {
        $accounts = fn () => MoneyAccount::query()->where('is_active', true)->pluck('name', 'id');

        return [
            Action::make('transfer')
                ->label('Nueva transferencia')
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create', Transfer::class)
                ->modalSubmitActionLabel('Transferir')
                ->schema([
                    Select::make('from_account_id')->label('Desde')->options($accounts)->required(),
                    Select::make('to_account_id')->label('Hacia')->options($accounts)->required()->different('from_account_id')
                        ->validationMessages(['different' => 'Elegí una cuenta distinta a la de origen.']),
                    TextInput::make('amount')->label('Monto')->prefix('₲')->numeric()->minValue(1)->required(),
                    DatePicker::make('transferred_on')->label('Fecha')->default(fn () => Filament::getTenant()->today()->toDateString())->required(),
                    TextInput::make('description')->label('Detalle')->placeholder('Depósito de la caja'),
                ])
                ->action(function (array $data): void {
                    app(TransferFunds::class)->handle(
                        MoneyAccount::query()->findOrFail($data['from_account_id']),
                        MoneyAccount::query()->findOrFail($data['to_account_id']),
                        (int) $data['amount'],
                        CarbonImmutable::parse($data['transferred_on']),
                        $data['description'] ?? null,
                        auth()->user(),
                    );

                    Notification::make()->success()->title('Transferencia registrada.')->send();
                }),
        ];
    }
}
