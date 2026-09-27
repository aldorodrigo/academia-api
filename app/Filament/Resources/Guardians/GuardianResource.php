<?php

namespace App\Filament\Resources\Guardians;

use App\Filament\Resources\Guardians\Pages\ManageGuardians;
use App\Filament\Resources\Guardians\Tables\GuardiansTable;
use App\Filament\Support\Terms;
use App\Models\Guardian;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class GuardianResource extends Resource
{
    protected static ?string $model = Guardian::class;

    protected static ?string $slug = 'tutores';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Personas';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'last_name';

    public static function getModelLabel(): string
    {
        return Terms::singular('guardian', 'Tutor');
    }

    public static function getPluralModelLabel(): string
    {
        return Terms::plural('guardian', 'Tutor');
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'email', 'document'];
    }

    /**
     * @return array<int, mixed>
     */
    public static function fields(): array
    {
        $inOrganization = fn (Unique $rule) => $rule->where('organization_id', filament()->getTenant()?->getKey());

        return [
            TextInput::make('first_name')->label('Nombre')->required()->maxLength(255),
            TextInput::make('last_name')->label('Apellido')->required()->maxLength(255),
            TextInput::make('document')->label('Documento')->maxLength(30)
                ->unique(ignoreRecord: true, modifyRuleUsing: $inOrganization),
            TextInput::make('email')->label('Correo')->email()->maxLength(255)
                ->helperText('Con el correo se le puede mandar la invitación a la app.'),
            TextInput::make('phone')->label('Teléfono')->tel()->maxLength(30),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(self::fields());
    }

    public static function table(Table $table): Table
    {
        return GuardiansTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ManageGuardians::route('/')];
    }
}
