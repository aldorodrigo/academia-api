<?php

namespace App\Filament\Resources\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Filament\Resources\Enrollments\Pages\ManageEnrollments;
use App\Filament\Resources\Enrollments\Pages\SeasonTransfer;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Support\SentenceCaseLabels;
use App\Filament\Support\Terms;
use App\Filament\Support\WithdrawalActions;
use App\Models\Enrollment;
use App\Models\Season;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class EnrollmentResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Enrollment::class;

    protected static ?string $slug = 'inscripciones';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Académico';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'inscripción';

    protected static ?string $pluralModelLabel = 'inscripciones';

    /**
     * Se inscribe solo desde la ficha del jugador (pestaña Inscripciones).
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Avisos de baja (técnico o familia) sin decidir.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Enrollment::query()->whereNotNull('dropout_reported_at')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Avisos de baja (técnico o familia)';
    }

    /**
     * Estados que se eligen a mano: la baja va con fecha y motivo ("Dar de baja").
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(EnrollmentStatus::cases())
            ->reject(fn (EnrollmentStatus $status) => $status === EnrollmentStatus::Withdrawn)
            ->mapWithKeys(fn (EnrollmentStatus $status) => [$status->value => $status->label()])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student', 'group.program', 'season', 'organization', 'dropoutReportedBy']))
            ->columns([
                TextColumn::make('student.last_name')->label(Terms::label('student', 'Jugador'))
                    ->formatStateUsing(fn (Enrollment $record) => "{$record->student->last_name}, {$record->student->first_name}")
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                TextColumn::make('group.program.name')->label(Terms::label('program', 'Disciplina')),
                TextColumn::make('group.name')->label(Terms::label('group', 'Categoría'))->sortable(),
                TextColumn::make('season.name')->label('Temporada'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn (Enrollment $record) => $record->statusLabel())
                    ->color(fn (Enrollment $record) => $record->statusColor())
                    ->description(fn (Enrollment $record) => WithdrawalActions::description($record)),
                TextColumn::make('enrolled_on')->label('Desde')->date('d/m/Y')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('season')
                    ->label('Temporada')
                    ->relationship('season', 'name')
                    ->multiple()
                    // Por defecto, las vigentes y próximas (puede haber varias a la vez).
                    ->default(fn () => Season::query()->open()->pluck('id')->all()),
                SelectFilter::make('group')->label(Terms::label('group', 'Categoría'))->relationship('group', 'name')->preload(),
                SelectFilter::make('status')->label('Estado')->options(EnrollmentStatus::class),
                Filter::make('dropout')->label('Con aviso de baja (técnico o familia)')->toggle()
                    ->query(fn (Builder $query) => $query->whereNotNull('dropout_reported_at')),
            ])
            // Editar o borrar una inscripción se hace en la ficha del jugador.
            ->recordUrl(fn (Enrollment $record) => StudentResource::getUrl('edit', ['record' => $record->student_id]))
            ->recordActions([
                WithdrawalActions::dismissDropout(),
                WithdrawalActions::withdraw(),
                WithdrawalActions::reactivate(),
                self::changeStatus(),
            ])
            ->toolbarActions([
                WithdrawalActions::withdrawBulk(),
                BulkAction::make('changeStatus')
                    ->label('Cambiar estado')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->schema([Select::make('status')->label('Estado')->options(self::statusOptions())->required()])
                    ->authorizeIndividualRecords('update')
                    // Las finalizadas (temporadas anteriores) no cambian; las dadas de baja se reactivan.
                    ->action(fn (Collection $records, array $data) => $records
                        ->reject(fn (Enrollment $record) => $record->isFinished() || $record->isWithdrawn())
                        ->each->update(['status' => $data['status']])),
            ]);
    }

    private static function changeStatus(): Action
    {
        return Action::make('changeStatus')
            ->label('Estado')
            ->icon(Heroicon::OutlinedArrowPath)
            ->fillForm(fn (Enrollment $record) => ['status' => $record->status])
            ->schema([Select::make('status')->label('Estado')->options(self::statusOptions())->required()])
            ->authorize('update')
            ->hidden(fn (Enrollment $record) => $record->isFinished() || $record->isWithdrawn())
            ->action(fn (Enrollment $record, array $data) => $record->update(['status' => $data['status']]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEnrollments::route('/'),
            'transfer' => SeasonTransfer::route('/pase'),
        ];
    }
}
