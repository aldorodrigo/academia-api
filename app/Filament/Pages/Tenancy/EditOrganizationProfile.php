<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\Feature;
use App\Enums\OrganizationType;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Configuración de la organización: datos, vocabulario y módulos.
 */
class EditOrganizationProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Configuración';
    }

    public static function canView($tenant): bool
    {
        $user = auth()->user();

        return $user->is_super_admin || $user->isOrganizationAdmin($tenant);
    }

    public function form(Schema $schema): Schema
    {
        $terminology = [
            'program' => 'Programa',
            'group' => 'Grupo',
            'student' => 'Alumno',
            'instructor' => 'Instructor',
            'guardian' => 'Tutor',
        ];

        return $schema->components([
            Section::make('Datos')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                    Select::make('type')
                        ->label('Tipo')
                        ->options(collect(OrganizationType::cases())->mapWithKeys(fn (OrganizationType $type) => [$type->value => $type->label()]))
                        ->required(),
                ]),
            Section::make('Vocabulario')
                ->description('Cómo se llaman las cosas en esta organización. Vacío = valor por defecto.')
                ->columns(2)
                ->schema(collect($terminology)->map(
                    fn (string $label, string $key) => TextInput::make("terminology.{$key}")
                        ->label($label)
                        ->placeholder(Organization::DEFAULT_TERMINOLOGY[$key])
                        ->maxLength(40),
                )->values()->all()),
            Section::make('Módulos')
                ->schema([
                    CheckboxList::make('features')
                        ->label('Módulos activos')
                        ->options(collect(Feature::cases())->mapWithKeys(fn (Feature $feature) => [$feature->value => $feature->label()]))
                        ->columns(2),
                ]),
        ]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['terminology'] = collect($data['terminology'] ?? [])
            ->map(fn (?string $value) => filled($value) ? trim($value) : null)
            ->filter()
            ->all() ?: null;

        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        return Filament::getUrl(Filament::getTenant());
    }
}
