<?php

namespace App\Filament\Resources\EnrollmentRequests;

use App\Actions\Enrollments\EnrollmentRequestAccess;
use App\Actions\Enrollments\ReviewEnrollmentRequest;
use App\Enums\EnrollmentRequestStatus;
use App\Enums\MidPeriod;
use App\Filament\Resources\EnrollmentRequests\Pages\ManageEnrollmentRequests;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Support\Terms;
use App\Models\EnrollmentRequest;
use App\Models\Group;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Solicitudes de inscripción que mandan las familias desde la app. Aprobar da de alta al chico (con
 * `RegisterStudent`, inscripción y cuotas del plan); rechazar le avisa al tutor con el motivo.
 */
class EnrollmentRequestResource extends Resource
{
    protected static ?string $model = EnrollmentRequest::class;

    protected static ?string $slug = 'solicitudes-de-inscripcion';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Académico';

    protected static ?string $navigationLabel = 'Solicitudes de inscripción';

    protected static ?string $modelLabel = 'solicitud de inscripción';

    protected static ?string $pluralModelLabel = 'solicitudes de inscripción';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && Filament::getTenant() !== null && EnrollmentRequestAccess::canReview($user);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = EnrollmentRequest::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'season', 'group.program', 'student', 'organization']))
            ->columns([
                TextColumn::make('created_at')->label('Pedida')->since()->sortable()
                    ->tooltip(fn (EnrollmentRequest $record) => $record->created_at->format('d/m/Y H:i')),
                TextColumn::make('first_name')->label(Terms::label('student', 'Jugador'))
                    ->state(fn (EnrollmentRequest $record) => $record->fullName())
                    ->description(fn (EnrollmentRequest $record) => self::childDetails($record))
                    ->url(fn (EnrollmentRequest $record) => $record->student_id
                        ? StudentResource::getUrl('edit', ['record' => $record->student_id])
                        : null)
                    ->searchable(['first_name', 'last_name', 'document']),
                TextColumn::make('group.name')->label(Terms::label('group', 'Categoría'))
                    ->state(fn (EnrollmentRequest $record) => "{$record->group->name} · {$record->group->program->name}")
                    ->description(fn (EnrollmentRequest $record) => $record->season->name),
                TextColumn::make('user.name')->label('Pidió')
                    ->description(fn (EnrollmentRequest $record) => $record->relationship->label()),
                TextColumn::make('status')->label('Estado')->badge()
                    ->description(fn (EnrollmentRequest $record) => $record->status === EnrollmentRequestStatus::Rejected
                        ? $record->rejection_reason
                        : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(EnrollmentRequestStatus::class)
                    ->default(EnrollmentRequestStatus::Pending->value),
            ])
            ->recordActions([
                self::approveAction(),
                self::rejectAction(),
            ]);
    }

    /**
     * "8 años · Doc. 7123456 · Ya está cargado (tutores: …) · Cargó la ficha médica".
     */
    private static function childDetails(EnrollmentRequest $record): string
    {
        $existing = $record->isPending() ? $record->existingStudent()?->load('guardians') : null;

        return collect([
            (int) $record->birth_date->diffInYears($record->organization->today()).' años',
            $record->document ? "Doc. {$record->document}" : null,
            $existing ? 'Ya está cargado'.($existing->guardians->isEmpty() ? '' : ' (tutores: '.$existing->guardians->pluck('full_name')->join(', ').')') : null,
            $record->medical !== null ? 'Cargó la ficha médica' : null,
            $record->notes,
        ])->filter()->join(' · ');
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprobar')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (EnrollmentRequest $record) => $record->isPending())
            ->modalHeading('Aprobar inscripción')
            ->modalSubmitActionLabel('Aprobar e inscribir')
            ->fillForm(fn (EnrollmentRequest $record) => [
                'group_id' => $record->group_id,
                'mid_period' => EnrollmentRequestAccess::midPeriod($record->season, $record->group, Filament::getTenant()->today())['default'] ?? null,
                'over_capacity' => false,
            ])
            ->schema(fn (EnrollmentRequest $record) => [
                Text::make("Se da de alta a {$record->fullName()} con sus cuotas según el plan de la temporada {$record->season->name}"
                    .($record->existingStudent() ? ' (ya está cargado: se le suma este tutor).' : '.')),
                Select::make('group_id')->label(Terms::label('group', 'Categoría'))
                    ->options(fn () => collect(self::groupOptions($record))->mapWithKeys(fn (array $group) => [$group['id'] => self::groupLabel($group)]))
                    ->required()
                    ->live(),
                Select::make('mid_period')
                    ->label(fn () => EnrollmentRequestAccess::midPeriod($record->season, $record->group, Filament::getTenant()->today())['label'] ?? '')
                    ->options(fn () => MidPeriod::optionsFor($record->season->billingUnit()))
                    ->visible(fn () => EnrollmentRequestAccess::midPeriod($record->season, $record->group, Filament::getTenant()->today()) !== null),
                Toggle::make('over_capacity')
                    ->label('Está completa: inscribir igual')
                    ->visible(fn (Get $get) => collect(self::groupOptions($record))->firstWhere('id', (int) $get('group_id'))['full'] ?? false)
                    ->accepted(),
            ])
            ->action(function (EnrollmentRequest $record, array $data): void {
                $request = app(ReviewEnrollmentRequest::class)->approve(
                    $record,
                    auth()->user(),
                    Group::query()->find($data['group_id']),
                    filled($data['mid_period'] ?? null) ? MidPeriod::from($data['mid_period']) : null,
                    (bool) ($data['over_capacity'] ?? false),
                );

                Notification::make()
                    ->success()
                    ->title("Inscripción aprobada: {$request->fullName()} en {$request->group->name}.")
                    ->body('Le avisamos al tutor.')
                    ->actions([
                        Action::make('student')->label('Ver ficha')->button()
                            ->url(StudentResource::getUrl('edit', ['record' => $request->student_id])),
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
            ->visible(fn (EnrollmentRequest $record) => $record->isPending())
            ->modalHeading('Rechazar solicitud')
            ->modalDescription('El tutor recibe el motivo y puede mandarla de nuevo.')
            ->schema([
                Textarea::make('reason')->label('Motivo')->placeholder('No hay lugar este año, falta un dato…')
                    ->required()->maxLength(500),
            ])
            ->action(function (EnrollmentRequest $record, array $data): void {
                app(ReviewEnrollmentRequest::class)->reject($record, auth()->user(), $data['reason']);

                Notification::make()->success()->title('Solicitud rechazada. Le avisamos al tutor.')->send();
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function groupOptions(EnrollmentRequest $record): array
    {
        return EnrollmentRequestAccess::groupOptions($record->season, $record->group->program, CarbonImmutable::parse($record->birth_date));
    }

    /**
     * "Sub-8 (por edad) · Quedan 3 lugares" / "Sub-8 · Completa (20)".
     *
     * @param  array<string, mixed>  $group
     */
    private static function groupLabel(array $group): string
    {
        return collect([
            $group['name'].($group['suggested'] ? ' (por edad)' : ''),
            match (true) {
                $group['full'] => "Completa ({$group['capacity']})",
                $group['spots_left'] === 1 => 'Queda 1 lugar',
                $group['spots_left'] !== null => "Quedan {$group['spots_left']} lugares",
                default => null,
            },
        ])->filter()->join(' · ');
    }

    public static function getPages(): array
    {
        return ['index' => ManageEnrollmentRequests::route('/')];
    }
}
