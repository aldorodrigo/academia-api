<?php

namespace App\Filament\Resources\CashDeposits;

use App\Actions\Billing\PaymentReportAccess;
use App\Actions\Treasury\CashDeposits;
use App\Enums\CashDepositStatus;
use App\Filament\Resources\CashDeposits\Pages\ManageCashDeposits;
use App\Filament\Support\MoneyColumn;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\CashDeposit;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Depósitos de efectivo que informan desde la app quienes cobran (técnicos, tesorero). La
 * plata sigue en su caja hasta que se confirma: ahí se registra la transferencia a la cuenta.
 */
class CashDepositResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = CashDeposit::class;

    protected static ?string $slug = 'depositos-de-efectivo';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'depósito de efectivo';

    protected static ?string $pluralModelLabel = 'depósitos de efectivo';

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        $organization = Filament::getTenant();

        return $user !== null && $organization !== null && PaymentReportAccess::canReview($user, $organization);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = CashDeposit::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'cashBox', 'toAccount', 'transfer', 'reviewedBy']))
            ->columns([
                TextColumn::make('created_at')->label('Informado')->since()->sortable()
                    ->tooltip(fn (CashDeposit $record) => $record->created_at->format('d/m/Y H:i'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.name')->label('Quién')->description(fn (CashDeposit $record) => $record->cashBox->name)->searchable(),
                TextColumn::make('deposited_on')->label('Depósito')->date('d/m/Y')
                    ->description(fn (CashDeposit $record) => collect([$record->toAccount->name, $record->reference ? "Ref. {$record->reference}" : null])->filter()->join(' · ')),
                MoneyColumn::make('amount')->label('Monto'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->state(fn (CashDeposit $record) => $record->displayStatus())
                    ->description(fn (CashDeposit $record) => match ($record->status) {
                        CashDepositStatus::Rejected => $record->rejection_reason,
                        CashDepositStatus::Confirmed => $record->reviewedBy ? "Confirmó {$record->reviewedBy->name}" : null,
                        default => $record->notes,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')
                    ->options(collect(CashDepositStatus::cases())->reject(fn ($status) => $status === CashDepositStatus::Voided)
                        ->mapWithKeys(fn (CashDepositStatus $status) => [$status->value => $status->label()])->all())
                    ->default(CashDepositStatus::Pending->value),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label('Confirmar')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (CashDeposit $record) => $record->isPending())
                    ->requiresConfirmation()
                    ->modalHeading(fn (CashDeposit $record) => self::confirmQuestion($record))
                    ->modalDescription(fn (CashDeposit $record) => 'Depositado el '.$record->deposited_on->format('d/m/Y')
                        .($record->reference ? " (ref. {$record->reference})" : '')
                        .". Se registra una transferencia de «{$record->cashBox->name}» a «{$record->toAccount->name}» y le avisamos a {$record->user->name}.")
                    ->modalSubmitActionLabel('Confirmar')
                    ->action(function (CashDeposit $record): void {
                        app(CashDeposits::class)->confirm($record, auth()->user());

                        Notification::make()->success()->title('Depósito confirmado. Le avisamos.')->send();
                    }),
                Action::make('reject')
                    ->label('Rechazar')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (CashDeposit $record) => $record->isPending())
                    ->modalHeading('Rechazar depósito')
                    ->modalDescription('La plata sigue en su caja y le llega el motivo.')
                    ->schema([
                        Textarea::make('reason')->label('Motivo')->placeholder('No llegó al banco, el monto no coincide…')
                            ->required()->maxLength(500),
                    ])
                    ->action(function (CashDeposit $record, array $data): void {
                        app(CashDeposits::class)->reject($record, auth()->user(), $data['reason']);

                        Notification::make()->success()->title('Depósito rechazado. Le avisamos.')->send();
                    }),
            ]);
    }

    /**
     * "¿Confirmás que llegaron ₲ 300.000 de Lucas Ferreira a Banco Itaú?" (lo mismo que pregunta la app).
     */
    public static function confirmQuestion(CashDeposit $record): string
    {
        return '¿Confirmás que llegaron '.Money::pyg($record->amount)->format()." de {$record->user->name} a {$record->toAccount->name}?";
    }

    public static function getPages(): array
    {
        return ['index' => ManageCashDeposits::route('/')];
    }
}
