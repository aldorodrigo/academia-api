<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Actions\Treasury\ExpenseLedger;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use App\Models\Supplier;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ManageExpenses extends ManageRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('register')
                ->label('Registrar gasto')
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create', Expense::class)
                ->modalHeading('Registrar gasto')
                ->modalSubmitActionLabel('Registrar')
                ->schema([
                    TextInput::make('description')->label('Detalle')->placeholder('Árbitros fecha 5')->required()->maxLength(255),
                    Select::make('expense_category_id')->label('Categoría')
                        ->options(fn () => ExpenseCategory::query()->orderBy('name')->pluck('name', 'id'))
                        ->createOptionForm([TextInput::make('name')->label('Nombre')->required()])
                        ->createOptionUsing(fn (array $data) => ExpenseCategory::query()->create($data)->id)
                        ->required(),
                    Select::make('supplier_id')->label('Proveedor')
                        ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->createOptionForm([TextInput::make('name')->label('Nombre')->required(), TextInput::make('phone')->label('Teléfono')])
                        ->createOptionUsing(fn (array $data) => Supplier::query()->create($data)->id),
                    TextInput::make('amount')->label('Monto')->prefix('₲')->numeric()->minValue(1)->required()->live(onBlur: true),
                    Select::make('money_account_id')->label('Sale de')
                        ->options(fn () => MoneyAccount::query()->where('is_active', true)->pluck('name', 'id'))
                        ->default(fn () => MoneyAccount::query()->value('id'))
                        ->required()
                        ->live(),
                    DatePicker::make('paid_on')->label('Fecha')->default(fn () => Filament::getTenant()->today()->toDateString())->required(),
                    FileUpload::make('attachment')->label('Comprobante')
                        ->disk('local')->directory('comprobantes')->visibility('private')
                        ->acceptedFileTypes(['image/*', 'application/pdf'])->maxSize(5120),
                    // Aviso si la cuenta quedaría en negativo (se permite: el libro mayor lo refleja).
                    Text::make(fn (Get $get) => $this->negativeWarning($get))
                        ->visible(fn (Get $get) => $this->negativeWarning($get) !== null),
                ])
                ->action(function (array $data): void {
                    app(ExpenseLedger::class)->register(
                        collect($data)->only(['description', 'expense_category_id', 'supplier_id', 'amount', 'attachment'])->all(),
                        MoneyAccount::query()->findOrFail($data['money_account_id']),
                        CarbonImmutable::parse($data['paid_on']),
                        auth()->user(),
                    );

                    Notification::make()->success()->title('Gasto registrado.')->send();
                }),
        ];
    }

    private function negativeWarning(Get $get): ?string
    {
        $account = MoneyAccount::query()->find($get('money_account_id'));
        $amount = (int) $get('amount');

        if ($account === null || $amount <= 0 || $account->balance() - $amount >= 0) {
            return null;
        }

        return "Atención: {$account->name} quedaría en ".Money::pyg($account->balance() - $amount)->format().'.';
    }
}
