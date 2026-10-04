<?php

namespace App\Filament\Support;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Role;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Campos para elegir un rol con su mandato (invitaciones y miembros).
 */
class RoleFields
{
    /**
     * @return array<string, string> nombre del rol => etiqueta
     */
    public static function options(): array
    {
        /** @var Organization $organization */
        $organization = Filament::getTenant();

        $user = auth()->user();
        // Quien no es administrador solo invita o asigna tutores y técnicos: no puede darle a otro
        // (ni a sí mismo) un cargo o el rol de administrador.
        $limited = $user !== null && ! $user->is_super_admin && ! $user->isOrganizationAdmin($organization);

        return Role::query()
            ->where('organization_id', $organization->id)
            ->when($limited, fn ($query) => $query->whereIn('name', [OrganizationRole::Guardian->value, OrganizationRole::Instructor->value]))
            ->orderBy('id')
            ->pluck('name')
            ->mapWithKeys(fn (string $name) => [$name => OrganizationRole::labelFor($name, $organization)])
            ->all();
    }

    public static function isBoardPosition(?string $role): bool
    {
        return (bool) OrganizationRole::tryFrom((string) $role)?->isBoardPosition();
    }

    /**
     * @return list<Component|Field>
     */
    public static function make(): array
    {
        return [
            Select::make('role')
                ->label('Rol')
                ->options(fn () => self::options())
                ->required()
                ->live()
                ->helperText(fn (Get $get) => OrganizationRole::tryFrom((string) $get('role'))?->description()),
            DatePicker::make('starts_on')
                ->label('Inicio del mandato')
                ->visible(fn (Get $get) => self::isBoardPosition($get('role'))),
            DatePicker::make('ends_on')
                ->label('Fin del mandato')
                ->visible(fn (Get $get) => self::isBoardPosition($get('role')))
                ->required(fn (Get $get) => self::isBoardPosition($get('role')))
                ->afterOrEqual('starts_on'),
        ];
    }
}
