<?php

namespace App\Filament\Pages\Tenancy;

use App\Actions\Organizations\RegisterOrganization as RegisterOrganizationAction;
use App\Enums\OrganizationType;
use App\Filament\Pages\Dashboard;
use App\Support\Onboarding\Templates;
use App\Support\Organizations\Slug;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * "Tu club": alta autoservicio de la organización. El usuario queda como administrador
 * y entra a la guía "Primeros pasos".
 */
class RegisterOrganization extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Registrá tu club';
    }

    /**
     * Cualquier cuenta (los módulos siguen siendo de la plataforma). Sin el email verificado,
     * el middleware de la página lleva primero a ingresar el código.
     */
    public static function canView(): bool
    {
        return auth()->check();
    }

    public function form(Schema $schema): Schema
    {
        $types = collect(Templates::organizationTypes());

        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->placeholder('Club Jakare, Academia Ritmo…')
                ->required()
                ->minLength(3)
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, Set $set) {
                    $slug = Slug::normalize((string) $state);
                    $set('slug', Slug::isAvailable($slug) ? $slug : Slug::suggest($slug));
                }),
            TextInput::make('slug')
                ->label('Identificador')
                ->helperText('Va en el link de inscripción y en la dirección del panel. No se puede cambiar después.')
                ->rules(Slug::rules())
                ->validationMessages([
                    'regex' => 'Solo letras minúsculas, números y guiones.',
                    'not_in' => 'Ese identificador no se puede usar.',
                    'unique' => 'Ese identificador ya está en uso.',
                ]),
            Radio::make('type')
                ->label('¿Qué es?')
                ->options($types->pluck('label', 'value'))
                ->descriptions($types->pluck('description', 'value'))
                ->default(OrganizationType::Club->value)
                ->required()
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set) {
                    foreach (Templates::terminologyFor(OrganizationType::from($state ?? 'club')) as $key => $term) {
                        $set("terminology.{$key}", $term);
                    }
                }),
            Section::make('¿Cómo les dicen?')
                ->description('Las pantallas van a usar estas palabras. Se pueden cambiar en Configuración.')
                ->collapsed()
                ->compact()
                ->schema([
                    Grid::make(3)->schema(collect([
                        'student' => 'A los que entrenan',
                        'instructor' => 'A quienes enseñan',
                        'group' => 'A los grupos',
                    ])->map(fn (string $label, string $key) => Select::make("terminology.{$key}")
                        ->label($label)
                        ->options(fn (Get $get) => collect(Templates::terminologyOptions()[$key])
                            ->push($get("terminology.{$key}"))
                            ->filter()->unique()->mapWithKeys(fn (string $term) => [$term => $term]))
                        ->default(Templates::terminologyFor(OrganizationType::Club)[$key])
                        ->selectablePlaceholder(false))
                        ->values()->all()),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        return app(RegisterOrganizationAction::class)->handle(auth()->user(), [
            ...$data,
            'terminology' => array_filter($data['terminology'] ?? []),
        ]);
    }

    protected function getRedirectUrl(): ?string
    {
        return Dashboard::getUrl(tenant: $this->tenant);
    }
}
