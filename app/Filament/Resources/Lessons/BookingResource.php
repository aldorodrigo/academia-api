<?php

namespace App\Filament\Resources\Lessons;

use App\Actions\Lessons\CancelBooking;
use App\Enums\BookingStatus;
use App\Filament\Resources\Lessons\Pages\ListBookings;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Booking;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class BookingResource extends Resource
{
    use LessonsModule;
    use SentenceCaseLabels;

    protected static ?string $model = Booking::class;

    protected static ?string $slug = 'reservas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Clases particulares';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'reserva';

    protected static ?string $pluralModelLabel = 'reservas';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student', 'teacher', 'classPack', 'charge.allocations.payment']))
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('starts_at')->label('Hora')->formatStateUsing(fn (Booking $record) => substr($record->starts_at, 0, 5).' a '.substr($record->ends_at, 0, 5)),
                TextColumn::make('teacher.name')->label('Profesor'),
                TextColumn::make('student.full_name')->label('Alumno'),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('payment')->label('Pago')->state(fn (Booking $record) => $record->usesPack()
                    ? 'Paquete'
                    : 'Suelta '.Money::pyg($record->price)->format().($record->charge === null ? '' : ($record->charge->pendingAmount() === 0 ? ' · pagada' : ' · debe'))),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('Profesor')
                    ->options(fn () => User::query()->whereIn('id', Booking::query()->distinct()->pluck('user_id'))->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('status')->label('Estado')->options(BookingStatus::class),
                Filter::make('date')->label('Fecha')
                    ->schema([DatePicker::make('from')->label('Desde'), DatePicker::make('until')->label('Hasta')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('date', '<=', $date))),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Cancelar')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Booking $record) => $record->canBeCancelled() && auth()->user()?->can('update', $record))
                    ->schema([TextInput::make('reason')->label('Motivo (se le avisa al alumno)')->maxLength(200)])
                    ->action(function (Booking $record, array $data, CancelBooking $cancel): void {
                        $cancel->handle($record, auth()->user(), byTeacher: true, reason: $data['reason'] ?? null);
                        Notification::make()->title('Reserva cancelada')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBookings::route('/')];
    }
}
