<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Actions\Billing\CashCollectionAccess;
use App\Actions\Billing\PaymentReportAccess;
use App\Actions\Billing\RegisterPayment;
use App\Enums\MoneyAccountType;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Payments\PaymentResource;
use App\Http\Controllers\ReceiptController;
use App\Models\Charge;
use App\Models\Family;
use App\Models\Guardian;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ManagePayments extends ManageRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->registerAction()];
    }

    /**
     * Registrar pago: familia → monto → cargos a cubrir (preseleccionados del más
     * viejo al más nuevo, con pronto pago) → resumen con el saldo a favor.
     */
    private function registerAction(): Action
    {
        $refresh = fn (Get $get, Set $set) => $set('charge_ids', $this->autoSelection($get));

        return Action::make('register')
            ->label('Registrar pago')
            ->icon(Heroicon::OutlinedPlus)
            ->authorize('create', Payment::class)
            ->modalHeading('Registrar pago')
            ->modalSubmitActionLabel('Registrar')
            ->schema([
                Select::make('family_id')
                    ->label('Familia')
                    ->placeholder('Buscá por jugador, tutor o documento')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => $this->searchFamilies($search))
                    ->getOptionLabelUsing(fn ($value) => $this->familyLabel(Family::query()->find($value)))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) use ($refresh): void {
                        $set('guardian_id', Guardian::query()->where('family_id', $get('family_id'))->value('id'));
                        $refresh($get, $set);
                    }),
                Grid::make(3)->schema([
                    TextInput::make('amount')->label('Monto recibido')->prefix('₲')->numeric()->minValue(1)->required()
                        ->live(onBlur: true)->afterStateUpdated($refresh),
                    DatePicker::make('received_on')->label('Fecha')->default(fn () => Filament::getTenant()->today()->toDateString())
                        ->required()->live()->afterStateUpdated($refresh),
                    Select::make('method')->label('Método')->options(PaymentMethod::class)->default(PaymentMethod::Cash->value)->required()
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => $set('money_account_id', self::defaultAccount($get('method')))),
                    // Efectivo: la caja de quien registra (como en la app) o la Caja del club si cobra directo; si no,
                    // la primera cuenta bancaria o billetera.
                    // De las cajas personales solo se ve la propia; la Caja del club y las demás cuentas, siempre.
                    Select::make('money_account_id')->label('Cuenta')
                        ->options(fn () => self::accountOptions())
                        ->default(fn () => self::defaultAccount(PaymentMethod::Cash))
                        ->required(),
                    Select::make('guardian_id')->label('Pagó')
                        ->options(fn (Get $get) => Guardian::query()->where('family_id', $get('family_id'))->get()
                            ->mapWithKeys(fn (Guardian $g) => [$g->id => $g->full_name])),
                    TextInput::make('reference')->label('Referencia')->placeholder('N° de transferencia'),
                ]),
                CheckboxList::make('charge_ids')
                    ->label('Cargos que cubre')
                    ->helperText('Se preseleccionan del más viejo al más nuevo hasta cubrir el monto.')
                    ->options(fn (Get $get) => $this->pendingOptions($get('family_id')))
                    ->visible(fn (Get $get) => filled($get('family_id')))
                    ->live(),
                Text::make(fn (Get $get) => $this->summary($get))
                    ->visible(fn (Get $get) => filled($get('family_id')) && filled($get('amount'))),
            ])
            ->action(function (array $data): void {
                $family = Family::query()->findOrFail($data['family_id']);
                $receivedOn = CarbonImmutable::parse($data['received_on']);

                $payment = app(RegisterPayment::class)->handle(
                    $family,
                    self::account($data['money_account_id']),
                    (int) $data['amount'],
                    PaymentMethod::from($data['method'] instanceof PaymentMethod ? $data['method']->value : $data['method']),
                    $receivedOn,
                    auth()->user(),
                    payer: filled($data['guardian_id'] ?? null) ? Guardian::query()->find($data['guardian_id']) : null,
                    allocations: $this->allocationsFor($family, (int) $data['amount'], $receivedOn, $data['charge_ids'] ?? []),
                    reference: $data['reference'] ?? null,
                );

                Notification::make()
                    ->success()
                    ->title("Pago registrado: recibo N° {$payment->receiptLabel()}.")
                    ->body($payment->credit() > 0 ? 'Saldo a favor: '.Money::pyg($payment->credit())->format().'.' : null)
                    ->actions([
                        Action::make('receipt')->label('Descargar recibo')->button()
                            ->url(ReceiptController::signedUrl($payment), shouldOpenInNewTab: true),
                    ])
                    ->persistent()
                    ->send();
            });
    }

    /** Valor de "mi caja" cuando quien registra todavía no tiene una (se crea al registrar). */
    private const OWN_CASH_BOX = 'mi-caja';

    /**
     * Cuentas del club y la caja personal de quien registra.
     *
     * @return array<int|string, string>
     */
    private static function accountOptions(): array
    {
        $own = MoneyAccount::cashBoxOf(auth()->user(), Filament::getTenant());

        // Cobra directo a la Caja: su caja personal solo si todavía le queda algo (para no ofrecerla de más).
        if (self::collectsToOrgCash() && ($own === null || $own->balance() === 0)) {
            return PaymentReportAccess::paymentAccounts()->pluck('name', 'id')->all();
        }

        // Unión (no `...`): el spread renumera las claves enteras (los ids).
        return [$own?->id ?? self::OWN_CASH_BOX => ($own?->name ?? 'Caja de '.auth()->user()->name).' (tu caja)']
            + PaymentReportAccess::paymentAccounts()->pluck('name', 'id')->all();
    }

    private static function defaultAccount(mixed $method): int|string|null
    {
        $method = $method instanceof PaymentMethod ? $method : PaymentMethod::tryFrom((string) $method);

        if ($method === PaymentMethod::Cash) {
            // Quien cobra directo a la Caja: la Caja del club; si no, su caja (lo deposita después).
            return self::collectsToOrgCash()
                ? CashCollectionAccess::orgCash()?->id
                : (MoneyAccount::cashBoxOf(auth()->user(), Filament::getTenant())?->id ?? self::OWN_CASH_BOX);
        }

        $accounts = PaymentReportAccess::paymentAccounts();

        return ($accounts->first(fn (MoneyAccount $account) => in_array($account->type, [MoneyAccountType::Bank, MoneyAccountType::Wallet], true))
            ?? $accounts->first())?->id;
    }

    private static function collectsToOrgCash(): bool
    {
        return CashCollectionAccess::collectsToOrgCash(auth()->user(), Filament::getTenant());
    }

    /**
     * La cuenta elegida: "mi caja" se crea si hace falta; otra caja personal no se acepta.
     */
    private static function account(int|string $id): MoneyAccount
    {
        $user = auth()->user();
        $organization = Filament::getTenant();

        if ($id === self::OWN_CASH_BOX) {
            return MoneyAccount::ensureCashBoxOf($user, $organization);
        }

        $account = MoneyAccount::query()->findOrFail($id);
        abort_if($account->isCashBox() && $account->user_id !== $user->id, 403, 'Elegí tu caja o una cuenta del club.');

        return $account;
    }

    /**
     * @return array<int, string>
     */
    private function searchFamilies(string $search): array
    {
        return Family::query()
            ->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('students', fn (Builder $q) => $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")->orWhere('document', $search))
                ->orWhereHas('guardians', fn (Builder $q) => $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")->orWhere('document', $search)))
            ->with('students')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (Family $family) => [$family->id => $this->familyLabel($family)])
            ->all();
    }

    private function familyLabel(?Family $family): ?string
    {
        return $family === null ? null
            : $family->name.' ('.$family->students->pluck('first_name')->join(', ').')';
    }

    /**
     * @return array<int, string>
     */
    private function pendingOptions(mixed $familyId): array
    {
        $family = filled($familyId) ? Family::query()->find($familyId) : null;

        return $family === null ? [] : app(RegisterPayment::class)->pendingCharges($family)
            ->mapWithKeys(fn (Charge $charge) => [$charge->id => sprintf(
                '%s · %s · %s%s',
                $charge->student->first_name,
                $charge->description,
                Money::pyg($charge->pendingAmount())->format(),
                $charge->status()->value === 'vencido' ? ' (vencido)' : '',
            )])
            ->all();
    }

    /**
     * Preselección: los cargos que cubre el monto, del más viejo al más nuevo.
     *
     * @return list<int>
     */
    private function autoSelection(Get $get): array
    {
        $family = filled($get('family_id')) ? Family::query()->find($get('family_id')) : null;

        if ($family === null || blank($get('amount'))) {
            return [];
        }

        $register = app(RegisterPayment::class);

        return array_column($register->plan($register->pendingCharges($family), (int) $get('amount'), $this->date($get)), 'charge_id');
    }

    /**
     * Lo que se imputa a cada cargo elegido (en orden de vencimiento), con pronto pago.
     *
     * @param  list<int|string>  $chargeIds
     * @return array<int, int>
     */
    private function allocationsFor(Family $family, int $amount, CarbonImmutable $date, array $chargeIds): array
    {
        $register = app(RegisterPayment::class);
        $selected = $register->pendingCharges($family)->whereIn('id', array_map('intval', $chargeIds))->values();

        return collect($register->plan($selected, $amount, $date))->pluck('amount', 'charge_id')->all();
    }

    private function summary(Get $get): string
    {
        $family = Family::query()->find($get('family_id'));
        $amount = (int) $get('amount');

        if ($family === null || $amount <= 0) {
            return '';
        }

        $register = app(RegisterPayment::class);
        $selected = $register->pendingCharges($family)->whereIn('id', array_map('intval', $get('charge_ids') ?? []))->values();
        $lines = collect($register->plan($selected, $amount, $this->date($get)));
        $applied = (int) $lines->sum('amount');
        $early = (int) $lines->sum('early_payment_discount');

        return collect([
            'Se aplican '.Money::pyg($applied)->format().' a '.$lines->count().' '.($lines->count() === 1 ? 'cargo' : 'cargos').'.',
            $early > 0 ? 'Pronto pago: −'.Money::pyg($early)->format().'.' : null,
            $amount > $applied ? 'Saldo a favor: '.Money::pyg($amount - $applied)->format().'.' : null,
        ])->filter()->join(' ');
    }

    private function date(Get $get): CarbonImmutable
    {
        return filled($get('received_on')) ? CarbonImmutable::parse($get('received_on')) : Filament::getTenant()->today();
    }
}
