<?php

namespace App\Filament\Resources\Roles;

use App\Actions\Roles\DeleteRole;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Organization;
use App\Models\Role;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

/**
 * "Roles y permisos" (Filament Shield) en español: el nombre visible de cada rol según el enum y el
 * vocabulario de la organización ("Síndico", "Técnico", "Administrador"; el nombre interno no cambia),
 * sin la columna "Guard" (es siempre la misma) y con fechas locales.
 *
 * Borrar: solo los roles creados por la organización que nadie tiene (`DeleteRole`, queda registrado quién y la foto
 * del rol); los roles base no muestran "Borrar". El administrador pasa por todo (`Gate::before`): en la lista dice
 * "Todos" y al editarlo se aclara que no hace falta tildar permisos.
 */
class RoleResource extends ShieldRoleResource
{
    use SentenceCaseLabels;

    public static function getModelLabel(): string
    {
        return 'rol';
    }

    public static function getPluralModelLabel(): string
    {
        return 'roles';
    }

    public static function isAdmin(?Model $record): bool
    {
        return $record !== null && $record->getAttribute('name') === OrganizationRole::Admin->value;
    }

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
                                Text::make('El administrador puede hacer todo, siempre: no hace falta tildar permisos y lo que se tilde acá no le cambia nada.')
                                    ->visible(fn (?Model $record) => self::isAdmin($record))
                                    ->columnSpanFull(),
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
                    ->formatStateUsing(fn (mixed $state, Model $record) => self::isAdmin($record) ? 'Todos' : $state)
                    ->color('primary'),
                TextColumn::make('updated_at')
                    ->label('Actualizado el')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Solo para un rol creado por la organización que nadie tiene; sin acción en masa.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (Role $record) => DeleteRole::canDelete($record))
            ->modalDescription('Nadie tiene este rol. Queda registrado quién lo borró, con su nombre y sus permisos.')
            ->using(function (Role $record, DeleteAction $action): bool {
                try {
                    app(DeleteRole::class)->handle($record, auth()->user());
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(collect($exception->errors())->flatten()->first())->send();
                    $action->halt();
                }

                return true;
            });
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
