<?php

namespace App\Filament\Support;

use App\Actions\Enrollments\ReactivateEnrollment;
use App\Actions\Enrollments\ReportDropout;
use App\Actions\Enrollments\WithdrawEnrollment;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Bajas en el panel (Inscripciones y ficha del jugador): dar de baja con fecha y motivo, reactivar
 * y descartar el aviso del técnico. La deuda queda: se condona aparte (Cuenta o Cargos).
 */
class WithdrawalActions
{
    public static function withdraw(): Action
    {
        return Action::make('withdraw')
            ->label('Dar de baja')
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->color('danger')
            ->authorize('update')
            ->visible(fn (Enrollment $record) => ! $record->isWithdrawn() && ! $record->isFinished())
            ->modalHeading(fn (Enrollment $record) => "Dar de baja a {$record->student->full_name}")
            ->modalDescription(fn (Enrollment $record) => self::effect($record))
            ->fillForm(fn (Enrollment $record) => [
                'ended_on' => $record->organization->today()->toDateString(),
                'withdrawal_reason' => $record->dropout_note ?? ($record->hasDropoutReport() ? 'Dejó de venir' : null),
            ])
            ->schema(self::fields())
            ->modalSubmitActionLabel('Dar de baja')
            ->action(function (Enrollment $record, array $data, Action $action): void {
                self::attempt($action, fn () => app(WithdrawEnrollment::class)->handle($record, $data['ended_on'], $data['withdrawal_reason'], auth()->user()));

                Notification::make()->success()->title('Baja registrada.')
                    ->body('Lo que debe sigue en su cuenta hasta que se pague o se condone.')->send();
            });
    }

    public static function withdrawBulk(): BulkAction
    {
        return BulkAction::make('withdraw')
            ->label('Dar de baja')
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->color('danger')
            ->authorizeIndividualRecords('update')
            ->modalDescription('Las cuotas impagas (también la del período en curso) siguen en su cuenta; se anulan solo las futuras sin pagar.')
            ->fillForm(fn () => ['ended_on' => filament()->getTenant()->today()->toDateString()])
            ->schema(self::fields())
            ->modalSubmitActionLabel('Dar de baja')
            ->action(function (Collection $records, array $data, BulkAction $action): void {
                $records = $records->reject(fn (Enrollment $record) => $record->isWithdrawn() || $record->isFinished());

                self::attempt($action, fn () => $records->each(
                    fn (Enrollment $record) => app(WithdrawEnrollment::class)->handle($record, $data['ended_on'], $data['withdrawal_reason'], auth()->user()),
                ));

                Notification::make()->success()->title($records->count() === 1 ? '1 baja registrada.' : "{$records->count()} bajas registradas.")->send();
            });
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivar')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->authorize('update')
            ->visible(fn (Enrollment $record) => $record->isWithdrawn() && ! $record->season->hasEnded())
            ->modalHeading(fn (Enrollment $record) => "Reactivar a {$record->student->full_name}")
            ->modalDescription('La deuda que dejó sigue en su cuenta. Las cuotas se emiten desde el período en curso: los meses que estuvo afuera no se cobran.')
            ->fillForm(['status' => EnrollmentStatus::Active->value])
            ->schema([
                Select::make('status')->label('Vuelve como')->required()
                    ->options(collect([EnrollmentStatus::Active, EnrollmentStatus::Scholarship])
                        ->mapWithKeys(fn (EnrollmentStatus $s) => [$s->value => $s->label()])->all()),
            ])
            ->modalSubmitActionLabel('Reactivar')
            ->action(function (Enrollment $record, array $data, Action $action): void {
                $pending = self::attempt($action, fn () => app(ReactivateEnrollment::class)->handle($record, EnrollmentStatus::from($data['status']), auth()->user()));

                Notification::make()->success()->title('Inscripción reactivada.')
                    ->body($pending > 0 ? 'Tiene pendiente '.Money::pyg($pending)->format().'.' : null)->send();
            });
    }

    public static function dismissDropout(): Action
    {
        return Action::make('dismissDropout')
            ->label('Sigue viniendo')
            ->icon(Heroicon::OutlinedCheck)
            ->color('gray')
            ->authorize('update')
            ->visible(fn (Enrollment $record) => $record->hasDropoutReport())
            ->requiresConfirmation()
            ->modalHeading('Descartar el aviso del técnico')
            ->modalDescription(fn (Enrollment $record) => "{$record->student->full_name} sigue inscripto y el aviso desaparece.")
            ->action(function (Enrollment $record): void {
                app(ReportDropout::class)->clear($record, auth()->user());

                Notification::make()->success()->title('Aviso descartado.')->send();
            });
    }

    /**
     * Debajo del estado: la baja (fecha y motivo) o el aviso del técnico.
     */
    public static function description(Enrollment $record): ?string
    {
        if ($record->isWithdrawn()) {
            return trim(($record->ended_on ? 'El '.$record->ended_on->format('d/m/Y') : '').($record->withdrawal_reason ? ': '.$record->withdrawal_reason : '')) ?: null;
        }

        if ($record->hasDropoutReport()) {
            $by = $record->dropoutReportedBy?->name ?? 'El técnico';

            return "{$by} avisó que dejó de venir ({$record->dropout_reported_at->setTimezone($record->organization->timezone)->format('d/m')})"
                .($record->dropout_note ? ": {$record->dropout_note}" : '');
        }

        return null;
    }

    /**
     * @return list<DatePicker|Textarea>
     */
    private static function fields(): array
    {
        return [
            DatePicker::make('ended_on')->label('Fecha de baja')->required()
                ->maxDate(fn () => filament()->getTenant()->today()),
            Textarea::make('withdrawal_reason')->label('Motivo')->required()->maxLength(255)
                ->placeholder('Ej.: se mudó, dejó de venir, cambió de club'),
        ];
    }

    /**
     * Qué pasa con la plata: lo pendiente queda y las futuras se anulan.
     */
    private static function effect(Enrollment $record): string
    {
        $preview = app(WithdrawEnrollment::class)->preview($record);
        $lines = [];

        $lines[] = $preview['pending_count'] > 0
            ? "Quedan pendientes {$preview['pending_count']} ".($preview['pending_count'] === 1 ? 'cuota' : 'cuotas').' por '.Money::pyg($preview['pending_amount'])->format()
                .' (también la del período en curso): siguen en su cuenta. Para no cobrarlas, condonalas desde la pestaña Cuenta.'
            : 'No tiene cuotas pendientes.';

        if ($preview['future_count'] > 0) {
            $lines[] = "Se anulan {$preview['future_count']} ".($preview['future_count'] === 1 ? 'cuota futura' : 'cuotas futuras').' sin pagar.';
        }

        return implode(' ', $lines);
    }

    /**
     * Corre la acción; si no se puede, avisa el motivo y deja el modal abierto.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function attempt(Action|BulkAction $action, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title(collect($exception->errors())->flatten()->first())->send();
            $action->halt();
        }
    }
}
