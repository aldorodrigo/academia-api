<?php

namespace App\Filament\Resources\Scholarships;

use App\Actions\Billing\ScholarshipDecision;
use App\Enums\ScholarshipStatus;
use App\Filament\Resources\Scholarships\Pages\ManageScholarships;
use App\Filament\Support\Terms;
use App\Models\Scholarship;
use BackedEnum;
use Filament\Actions\Action;
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
 * Becas: se cargan pendientes y las aprueba quien tiene el permiso "Aprobar becas".
 */
class ScholarshipResource extends Resource
{
    protected static ?string $model = Scholarship::class;

    protected static ?string $slug = 'becas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'beca';

    protected static ?string $pluralModelLabel = 'becas';

    public static function getNavigationBadge(): ?string
    {
        $pending = Scholarship::query()->where('status', ScholarshipStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student', 'enrollment.group', 'requestedBy', 'decidedBy']))
            ->columns([
                TextColumn::make('student.last_name')->label(Terms::label('student', 'Jugador'))
                    ->formatStateUsing(fn (Scholarship $record) => "{$record->student->last_name}, {$record->student->first_name}")
                    ->description(fn (Scholarship $record) => $record->enrollment->group->name)
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('percent')->label('Beca')->formatStateUsing(fn (int $state) => $state >= 100 ? 'Total' : "{$state} %"),
                TextColumn::make('reason')->label('Motivo')->limit(40)->tooltip(fn (Scholarship $record) => $record->reason),
                TextColumn::make('valid_from')->label('Desde')->date('d/m/Y'),
                TextColumn::make('valid_to')->label('Hasta')->date('d/m/Y')->placeholder('Sin fin'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->description(fn (Scholarship $record) => $record->decidedBy ? "por {$record->decidedBy->name}" : "cargó {$record->requestedBy?->name}"),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->label('Estado')->options(ScholarshipStatus::class)])
            ->recordActions([
                self::decision('approve', 'Aprobar', Heroicon::OutlinedCheck, 'success', ScholarshipStatus::Pending),
                self::decision('reject', 'Rechazar', Heroicon::OutlinedXMark, 'gray', ScholarshipStatus::Pending),
                self::decision('revoke', 'Revocar', Heroicon::OutlinedNoSymbol, 'danger', ScholarshipStatus::Approved),
            ]);
    }

    private static function decision(string $name, string $label, Heroicon $icon, string $color, ScholarshipStatus $from): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (Scholarship $record) => $record->status === $from && auth()->user()->can('approve', $record))
            ->requiresConfirmation()
            ->schema([Textarea::make('note')->label('Nota')->required($name !== 'approve')])
            ->action(function (Scholarship $record, array $data) use ($name, $label): void {
                app(ScholarshipDecision::class)->{$name}($record, auth()->user(), $data['note'] ?? null);

                Notification::make()->success()->title("Beca: {$label}.")->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageScholarships::route('/')];
    }
}
