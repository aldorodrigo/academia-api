<?php

namespace App\Filament\Resources\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Filament\Resources\Enrollments\Pages\ManageEnrollments;
use App\Filament\Support\EnrollmentFields;
use App\Filament\Support\Terms;
use App\Models\Enrollment;
use App\Models\Season;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class EnrollmentResource extends Resource
{
    protected static ?string $model = Enrollment::class;

    protected static ?string $slug = 'inscripciones';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Académico';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'inscripción';

    protected static ?string $pluralModelLabel = 'inscripciones';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('student_id')
                ->label(Terms::label('student', 'Jugador'))
                ->relationship('student', 'last_name')
                ->getOptionLabelFromRecordUsing(fn ($student) => "{$student->last_name}, {$student->first_name}")
                ->searchable(['first_name', 'last_name', 'document'])
                ->required(),
            ...EnrollmentFields::make(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student', 'group.program', 'season']))
            ->columns([
                TextColumn::make('student.last_name')->label(Terms::label('student', 'Jugador'))
                    ->formatStateUsing(fn (Enrollment $record) => "{$record->student->last_name}, {$record->student->first_name}")
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                TextColumn::make('group.program.name')->label(Terms::label('program', 'Disciplina')),
                TextColumn::make('group.name')->label(Terms::label('group', 'Categoría'))->sortable(),
                TextColumn::make('season.name')->label('Temporada'),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('enrolled_on')->label('Desde')->date('d/m/Y')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('season')
                    ->label('Temporada')
                    ->relationship('season', 'name')
                    ->default(fn () => Season::currentOrNull()?->id),
                SelectFilter::make('group')->label(Terms::label('group', 'Categoría'))->relationship('group', 'name')->preload(),
                SelectFilter::make('status')->label('Estado')->options(EnrollmentStatus::class),
            ])
            ->recordActions([self::changeStatus(), EditAction::make()])
            ->toolbarActions([
                BulkAction::make('changeStatus')
                    ->label('Cambiar estado')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->schema([Select::make('status')->label('Estado')->options(EnrollmentStatus::class)->required()])
                    ->authorizeIndividualRecords('update')
                    ->action(fn (Collection $records, array $data) => $records->each->update(['status' => $data['status']])),
            ]);
    }

    private static function changeStatus(): Action
    {
        return Action::make('changeStatus')
            ->label('Estado')
            ->icon(Heroicon::OutlinedArrowPath)
            ->fillForm(fn (Enrollment $record) => ['status' => $record->status])
            ->schema([Select::make('status')->label('Estado')->options(EnrollmentStatus::class)->required()])
            ->authorize('update')
            ->action(fn (Enrollment $record, array $data) => $record->update(['status' => $data['status']]));
    }

    public static function getPages(): array
    {
        return ['index' => ManageEnrollments::route('/')];
    }
}
