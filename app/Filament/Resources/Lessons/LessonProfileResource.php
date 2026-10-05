<?php

namespace App\Filament\Resources\Lessons;

use App\Enums\OrganizationRole;
use App\Filament\Resources\Lessons\Pages\ManageLessonProfiles;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\LessonProfile;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class LessonProfileResource extends Resource
{
    use LessonsModule;
    use SentenceCaseLabels;

    protected static ?string $model = LessonProfile::class;

    protected static ?string $slug = 'profesores-particulares';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Clases particulares';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'profesor';

    protected static ?string $pluralModelLabel = 'profesores';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('user_id')->label('Profesor')->required()
                ->options(fn () => User::query()
                    ->whereHas('roleAssignments', fn (Builder $query) => $query
                        ->where('organization_id', Filament::getTenant()?->getKey())
                        ->whereHas('role', fn (Builder $role) => $role->where('name', OrganizationRole::Instructor->value)))
                    ->orderBy('name')->pluck('name', 'id'))
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('organization_id', Filament::getTenant()?->getKey()))
                ->disabledOn('edit'),
            Toggle::make('enabled')->label('Recibe reservas')->inline(false),
            TextInput::make('single_price')->label('Precio de la clase suelta')->numeric()->minValue(1)->required()->prefix('₲'),
            Select::make('duration_minutes')->label('Duración')->required()
                ->options([30 => '30 min', 45 => '45 min', 60 => '1 h', 90 => '1 h 30', 120 => '2 h']),
            Select::make('min_notice_minutes')->label('Anticipación mínima')->required()
                ->options([0 => 'Sin anticipación', 60 => '1 hora', 120 => '2 horas', 240 => '4 horas', 1440 => '1 día']),
            TextInput::make('days_ahead')->label('Días hacia adelante para reservar')->numeric()->minValue(1)->maxValue(90)->required(),
            Select::make('money_account_id')->label('Cuenta donde entra lo que cobra')
                ->options(fn () => MoneyAccount::query()->where('is_active', true)->pluck('name', 'id'))
                ->placeholder('La primera cuenta activa'),
            Repeater::make('packs')->label('Paquetes')->relationship('packs')->columnSpanFull()->columns(3)
                ->schema([
                    TextInput::make('classes')->label('Clases')->numeric()->minValue(1)->required(),
                    TextInput::make('price')->label('Precio')->numeric()->minValue(1)->required()->prefix('₲'),
                    TextInput::make('valid_days')->label('Validez (días)')->numeric()->minValue(1)->maxValue(365)
                        ->placeholder('Sin vencimiento')->helperText('Cuenta desde que se paga.'),
                ])
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => [...$data, 'organization_id' => Filament::getTenant()?->getKey()]),
            Repeater::make('availability')->label('Disponibilidad')->relationship('availability')->columnSpanFull()->columns(3)
                ->schema([
                    Select::make('weekday')->label('Día')->required()
                        ->options([1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo']),
                    TimePicker::make('starts_at')->label('Desde')->seconds(false)->required(),
                    TimePicker::make('ends_at')->label('Hasta')->seconds(false)->required()->after('starts_at'),
                ])
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => [...$data, 'organization_id' => Filament::getTenant()?->getKey()]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user')->withCount('packs'))
            ->columns([
                TextColumn::make('user.name')->label('Profesor'),
                IconColumn::make('enabled')->label('Recibe reservas')->boolean(),
                TextColumn::make('single_price')->label('Clase suelta')->formatStateUsing(fn ($state) => Money::pyg((int) $state)->format())->alignEnd(),
                TextColumn::make('packs_count')->label('Paquetes'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLessonProfiles::route('/')];
    }
}
