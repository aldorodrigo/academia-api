<?php

namespace App\Filament\Resources\Roles;

use App\Enums\OrganizationRole;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Models\Organization;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * "Roles y permisos" (Filament Shield) en español: el nombre visible de cada rol según el enum y el
 * vocabulario de la organización ("Síndico", "Técnico", "Administrador"; el nombre interno no cambia),
 * sin la columna "Guard" (es siempre la misma) y con fechas locales.
 */
class RoleResource extends ShieldRoleResource
{
    public static function label(?string $name): string
    {
        $tenant = Filament::getTenant();

        return OrganizationRole::labelFor((string) $name, $tenant instanceof Organization ? $tenant : null);
    }

    public static function getRecordTitle(?Model $record): string
    {
        return self::label($record?->getAttribute('name'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make()
                    ->schema([
                        Section::make()
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nombre')
                                    ->helperText(fn (?Model $record) => $record !== null && OrganizationRole::tryFrom((string) $record->getAttribute('name')) !== null
                                        ? 'Se muestra como «'.self::label($record->getAttribute('name')).'».'
                                        : null)
                                    ->unique(
                                        ignoreRecord: true,
                                        modifyRuleUsing: fn (Unique $rule): Unique => Utils::isTenancyEnabled() ? $rule->where(Utils::getTenantModelForeignKey(), Filament::getTenant()?->getKey()) : $rule
                                    )
                                    ->required()
                                    ->maxLength(255),
                                Hidden::make('guard_name')->default(Utils::getFilamentAuthGuard()),
                                static::getSelectAllFormComponent(),
                            ])
                            ->columns(['sm' => 2, 'lg' => 3])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                static::getShieldFormComponents(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->weight(FontWeight::Medium)
                    ->formatStateUsing(fn (?string $state): string => self::label($state))
                    ->searchable(),
                TextColumn::make('permissions_count')
                    ->label('Permisos')
                    ->badge()
                    ->counts('permissions')
                    ->color('primary'),
                TextColumn::make('updated_at')
                    ->label('Actualizado el')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
