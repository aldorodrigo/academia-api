<?php

namespace App\Filament\Resources\PaymentReports;

use App\Actions\Billing\PaymentReportAccess;
use App\Actions\Billing\ReviewPaymentReport;
use App\Enums\MoneyAccountType;
use App\Enums\PaymentReportStatus;
use App\Filament\Resources\PaymentReports\Pages\ManagePaymentReports;
use App\Filament\Support\MoneyColumn;
use App\Filament\Support\ReceiptNoticeNotification;
use App\Filament\Support\SentenceCaseLabels;
use App\Filament\Support\Terms;
use App\Http\Controllers\PaymentProofController;
use App\Http\Controllers\ReceiptController;
use App\Models\Charge;
use App\Models\MoneyAccount;
use App\Models\PaymentReport;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Comprobantes de transferencia que informan los tutores desde la app. Aprobar
 * registra el pago con su recibo; rechazar le avisa al tutor con el motivo.
 */
class PaymentReportResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = PaymentReport::class;

    protected static ?string $slug = 'comprobantes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'comprobante';

    protected static ?string $pluralModelLabel = 'comprobantes';

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
        $pending = PaymentReport::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['family.students', 'user', 'moneyAccount', 'payment', 'reviewedBy']))
            ->columns([
                TextColumn::make('created_at')->label('Informado')->since()->sortable()
                    ->tooltip(fn (PaymentReport $record) => $record->created_at->format('d/m/Y H:i'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('family.name')->label('Familia')
                    ->description(fn (PaymentReport $record) => $record->family->students->pluck('first_name')->join(', ').' · '
                        .($record->registered_by_staff ? "registró {$record->user->name}" : $record->user->name))
                    ->searchable(),
                TextColumn::make('paid_on')->label('Transferencia')->date('d/m/Y')
                    ->description(fn (PaymentReport $record) => collect([$record->moneyAccount?->name, $record->reference ? "Ref. {$record->reference}" : null])->filter()->join(' · ')),
                TextColumn::make('charge_ids')->label('Cuotas')
                    ->state(fn (PaymentReport $record) => $record->charges()
                        ->map(fn (Charge $charge) => "{$charge->student->first_name} · {$charge->description}")
                        ->whenEmpty(fn ($lines) => $lines->push('Pago a cuenta'))
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList(),
                MoneyColumn::make('amount')->label('Monto'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->description(fn (PaymentReport $record) => match ($record->status) {
                        PaymentReportStatus::Approved => "Recibo N° {$record->payment?->receiptLabel()}",
                        PaymentReportStatus::Rejected => $record->rejection_reason,
                        default => null,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(PaymentReportStatus::class)
                    ->default(PaymentReportStatus::Pending->value),
            ])
            ->recordActions([
                // Íconos: así Aprobar y Rechazar entran en pantalla.
                Action::make('proof')
                    ->label('Ver comprobante')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->url(fn (PaymentReport $record) => PaymentProofController::signedUrl($record))
                    ->openUrlInNewTab(),
                Action::make('receipt')
                    ->label('Ver recibo')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn (PaymentReport $record) => $record->payment !== null)
                    ->url(fn (PaymentReport $record) => ReceiptController::signedUrl($record->payment))
                    ->openUrlInNewTab(),
                self::approveAction(),
                self::rejectAction(),
            ]);
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprobar')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (PaymentReport $record) => $record->isPending())
            ->modalHeading('Aprobar pago')
            ->modalSubmitActionLabel('Aprobar y registrar el pago')
            ->fillForm(fn (PaymentReport $record) => [
                'money_account_id' => self::defaultAccountId($record),
                'received_on' => $record->paid_on->toDateString(),
                'amount' => $record->amount,
            ])
            ->schema(fn (PaymentReport $record) => [
                Text::make(fn () => 'Se registra un pago por transferencia de la '.$record->family->name.', imputado a: '
                    .$record->charges()->map(fn (Charge $charge) => "{$charge->student->first_name} · {$charge->description} (".Money::pyg($charge->pendingAmount())->format().')')
                        ->whenEmpty(fn ($lines) => $lines->push('las cuotas pendientes, de la más vieja a la más nueva'))
                        ->join(', ')
                    .'. Lo que sobre queda como saldo a favor.'),
                Select::make('money_account_id')->label('Entró en')
                    ->options(fn () => PaymentReportAccess::paymentAccounts()->pluck('name', 'id'))
                    ->required(),
                DatePicker::make('received_on')->label('Fecha')->required()
                    ->maxDate(fn () => Filament::getTenant()->today()),
                TextInput::make('amount')->label('Monto acreditado')->prefix('₲')->numeric()->minValue(1)->required(),
            ])
            ->action(function (PaymentReport $record, array $data): void {
                $report = app(ReviewPaymentReport::class)->approve(
                    $record,
                    auth()->user(),
                    MoneyAccount::query()->findOrFail($data['money_account_id']),
                    CarbonImmutable::parse($data['received_on']),
                    (int) $data['amount'],
                );

                // La registró alguien del club (la familia no la informó): el recibo le llega a la familia solo si
                // tiene la app; se dice la verdad y se ofrece WhatsApp. Si la informó el tutor, le llega en la app.
                if ($record->registered_by_staff) {
                    ReceiptNoticeNotification::make($report->payment, "Pago aprobado: recibo N° {$report->payment->receiptLabel()}.")->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title("Pago aprobado: recibo N° {$report->payment->receiptLabel()}.")
                    ->body('Le avisamos '.Terms::toPerson('guardian', 'Tutor', $record->reporterGender()).'.')
                    ->actions([
                        Action::make('receipt')->label('Descargar recibo')->button()
                            ->url(ReceiptController::signedUrl($report->payment), shouldOpenInNewTab: true),
                    ])
                    ->send();
            });
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Rechazar')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (PaymentReport $record) => $record->isPending())
            ->modalHeading('Rechazar comprobante')
            ->modalDescription(fn (PaymentReport $record) => $record->registered_by_staff
                ? "{$record->user->name} lo registró: recibe el motivo y puede registrarlo de nuevo."
                : ucfirst(Terms::thePerson('guardian', 'Tutor', $record->reporterGender())).' recibe el motivo y puede informar el pago de nuevo.')
            ->schema([
                Textarea::make('reason')->label('Motivo')->placeholder('El comprobante no se lee, el monto no coincide…')
                    ->required()->maxLength(500),
            ])
            ->action(function (PaymentReport $record, array $data): void {
                app(ReviewPaymentReport::class)->reject($record, auth()->user(), $data['reason']);

                // Si la registró alguien del club, el motivo le llega a esa persona, no a la familia.
                Notification::make()->success()->title('Comprobante rechazado. Le avisamos '.($record->registered_by_staff
                    ? 'a '.$record->user->name
                    : Terms::toPerson('guardian', 'Tutor', $record->reporterGender())).'.')->send();
            });
    }

    private static function defaultAccountId(PaymentReport $record): ?int
    {
        $accounts = PaymentReportAccess::paymentAccounts();

        return ($accounts->firstWhere('id', $record->money_account_id)
            ?? $accounts->firstWhere('type', MoneyAccountType::Bank)
            ?? $accounts->first())?->id;
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentReports::route('/')];
    }
}
