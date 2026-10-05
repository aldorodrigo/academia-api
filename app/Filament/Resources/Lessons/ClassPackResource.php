<?php

namespace App\Filament\Resources\Lessons;

use App\Actions\Lessons\ExtendClassPack;
use App\Enums\ClassPackStatus;
use App\Filament\Resources\Lessons\Pages\ListClassPacks;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\ClassPack;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ClassPackResource extends Resource
{
    use LessonsModule;
    // "Gastos recurrentes", no "Gastos Recurrentes".
    use SentenceCaseLabels;

    protected static ?string $model = ClassPack::class;

    protected static ?string $slug = 'paquetes-de-clases';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Clases particulares';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'paquete de clases';

    protected static ?string $pluralModelLabel = 'paquetes de clases';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student', 'teacher']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('student.full_name')->label('Alumno'),
                TextColumn::make('teacher.name')->label('Profesor'),
                TextColumn::make('classes')->label('Clases')->formatStateUsing(fn (ClassPack $record) => "Usó {$record->used} de {$record->classes}"),
                TextColumn::make('price')->label('Precio')->formatStateUsing(fn ($state) => Money::pyg((int) $state)->format())->alignEnd(),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('expires_on')->label('Vence')->date('d/m/Y')->placeholder('Sin vencimiento'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(ClassPackStatus::class),
            ])
            ->recordActions([
                Action::make('extend')
                    ->label('Extender')
                    ->icon(Heroicon::OutlinedCalendar)
                    ->visible(fn (ClassPack $record) => in_array($record->status, [ClassPackStatus::Active, ClassPackStatus::Expired], true)
                        && $record->remaining() > 0
                        && auth()->user()?->can('update', $record))
                    ->schema([DatePicker::make('expires_on')->label('Nuevo vencimiento')->required()])
                    ->action(function (ClassPack $record, array $data, ExtendClassPack $extend): void {
                        $extend->handle($record, CarbonImmutable::parse($data['expires_on'])->startOfDay());
                        Notification::make()->title('Paquete extendido')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListClassPacks::route('/')];
    }
}
