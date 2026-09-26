<?php

namespace App\Filament\Platform\Resources\Organizations\Schemas;

use App\Enums\Feature;
use App\Enums\OrganizationType;
use App\Models\Organization;
use DateTimeZone;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class OrganizationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }

    /**
     * @param  bool  $withAdminEmail  alta: pide el email del primer administrador
     * @return list<Component>
     */
    public static function fields(bool $withAdminEmail = false): array
    {
        return [
            Section::make('Datos')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Set $set, ?Organization $record) {
                            if ($record === null) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')
                        ->label('Identificador (slug)')
                        ->helperText('Va en la URL del panel y en la app. No se puede cambiar después.')
                        ->required()
                        ->alphaDash()
                        ->maxLength(60)
                        ->unique(Organization::class, 'slug', ignoreRecord: true)
                        ->disabled(fn (?Organization $record) => $record !== null)
                        ->dehydrated(fn (?Organization $record) => $record === null),
                    Select::make('type')
                        ->label('Tipo')
                        ->options(collect(OrganizationType::cases())->mapWithKeys(fn (OrganizationType $type) => [$type->value => $type->label()]))
                        ->default(OrganizationType::Club->value)
                        ->required(),
                    Select::make('timezone')
                        ->label('Zona horaria')
                        ->options(collect(DateTimeZone::listIdentifiers())->mapWithKeys(fn (string $tz) => [$tz => $tz]))
                        ->default('America/Asuncion')
                        ->searchable()
                        ->required(),
                    TextInput::make('country')
                        ->label('País (ISO)')
                        ->default('PY')
                        ->length(2)
                        ->required(),
                    TextInput::make('currency')
                        ->label('Moneda (ISO)')
                        ->default('PYG')
                        ->length(3)
                        ->required(),
                ]),
            Section::make('Módulos contratados')
                ->schema([
                    CheckboxList::make('features')
                        ->hiddenLabel()
                        ->options(collect(Feature::cases())->mapWithKeys(fn (Feature $feature) => [$feature->value => $feature->label()]))
                        ->columns(2),
                ]),
            ...($withAdminEmail ? [
                Section::make('Primer administrador')
                    ->description('Le llega una invitación para crear su cuenta y administrar la organización.')
                    ->schema([
                        TextInput::make('admin_email')
                            ->label('Correo electrónico')
                            ->email()
                            ->required(),
                    ]),
            ] : []),
        ];
    }
}
