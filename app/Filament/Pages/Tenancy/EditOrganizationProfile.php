<?php

namespace App\Filament\Pages\Tenancy;

use App\Actions\Organizations\UpdateTerminology;
use App\Enums\AdjustmentType;
use App\Enums\Feature;
use App\Enums\OrganizationType;
use App\Filament\Support\Terms;
use App\Models\Organization;
use App\Support\Onboarding\Templates;
use App\Support\Vocabulary;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Configuración de la organización: datos, vocabulario, cobros, asistencia y módulos.
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
        $tenant = Filament::getTenant();
        $typeTerms = Templates::terminologyFor($tenant->type ?? OrganizationType::Club);
        // "A los responsables de cada jugador" (con la palabra de la organización).
        $questions = [
            ...Templates::termQuestions(),
            'guardian' => 'A los responsables de cada '.mb_strtolower($tenant->term('student')),
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
                ->description('¿Cómo les dicen? Las pantallas de la app y del panel usan estas palabras (en singular). '
                    .'Vacía = la que se usa en '.Vocabulary::a(($tenant->type ?? OrganizationType::Club)->noun()).'.')
                ->columns(2)
                ->schema(collect($questions)->map(
                    fn (string $label, string $key) => TextInput::make("terminology.{$key}")
                        ->label($label)
                        ->placeholder($typeTerms[$key])
                        ->datalist(collect(Templates::terminologyOptions()[$key] ?? [])->push($typeTerms[$key])->unique()->values()->all())
                        ->helperText('Ej.: '.collect(Templates::terminologyOptions()[$key] ?? [$typeTerms[$key]])->take(3)->implode(', ').'.')
                        ->maxLength(30),
                )->values()->all()),
            Section::make('Para nombrar a una mujer')
                ->description('Cuando se sabe que es una mujer (su género, o el parentesco de un tutor) la nombramos así: '
                    .'"Te invitaron como Técnica", "Jugadora" en el recibo. Vacía = la que sale de la palabra de arriba.')
                ->columns(3)
                ->collapsed()
                ->schema(collect(Organization::PERSON_TERMS)->map(
                    fn (string $key) => TextInput::make("terminology_feminine.{$key}")
                        ->label(fn (Get $get) => $get("terminology.{$key}") ?: $typeTerms[$key])
                        ->placeholder(fn (Get $get) => Vocabulary::feminine($get("terminology.{$key}") ?: $typeTerms[$key]))
                        ->maxLength(30),
                )->all()),
            Section::make('Cobros')
                ->description('Vencimiento de las cuotas, orden de los descuentos y mora.')
                ->columns(2)
                ->schema([
                    TextInput::make('billing.due_day')
                        ->label('Día de vencimiento')
                        ->helperText('Las cuotas vencen ese día de cada mes (o el último, si el mes es más corto).')
                        ->numeric()->minValue(1)->maxValue(31)->required(),
                    TextInput::make('billing.grace_days')
                        ->label('Días de gracia')
                        ->helperText('Días después del vencimiento antes de figurar como vencida.')
                        ->numeric()->minValue(0)->maxValue(60)->required(),
                    Repeater::make('billing.discount_order')
                        ->label('Orden de los descuentos')
                        ->helperText('Cada descuento se calcula sobre lo que queda del anterior. Arrastrá para cambiar el orden.')
                        ->simple(Select::make('type')->options(collect(AdjustmentType::DISCOUNTS)
                            ->mapWithKeys(fn (AdjustmentType $type) => [$type->value => $type->getLabel()]))->disabled()->dehydrated())
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable()
                        ->columnSpanFull(),
                    Fieldset::make('Recargo por mora')
                        ->columnSpanFull()
                        ->columns(4)
                        ->schema([
                            Toggle::make('billing.late_fee.enabled')->label('Cobrar recargo')->live()->columnSpanFull()
                                ->helperText('Se aplica desde el registro de pagos (próxima etapa); ahora queda configurado.'),
                            Select::make('billing.late_fee.type')->label('Tipo')
                                ->options(['percent' => 'Porcentaje', 'fixed' => 'Monto fijo'])
                                ->visible(fn (Get $get) => $get('billing.late_fee.enabled')),
                            TextInput::make('billing.late_fee.value')->label('Valor')->numeric()->minValue(0)
                                ->visible(fn (Get $get) => $get('billing.late_fee.enabled')),
                            Select::make('billing.late_fee.frequency')->label('Frecuencia')
                                ->options(['once' => 'Una vez', 'monthly' => 'Cada mes de atraso'])
                                ->visible(fn (Get $get) => $get('billing.late_fee.enabled')),
                            TextInput::make('billing.late_fee.cap')->label('Tope (₲)')->numeric()->minValue(0)
                                ->visible(fn (Get $get) => $get('billing.late_fee.enabled')),
                        ]),
                ]),
            Section::make('Asistencia')
                ->schema([
                    TextInput::make('class_reminder_hours')
                        ->label('Aviso de día de clase')
                        ->helperText(fn () => 'Horas antes de cada clase en que sale el aviso "¿Lo llevás?" a '.Terms::the('guardian', 'Tutor', plural: true).' que lo pidieron. Si cae de noche, sale a las 20:00 del día anterior.')
                        ->numeric()->integer()->minValue(1)->maxValue(24)->suffix('horas antes')->required(),
                    TextInput::make('instructor_reminder_hours')
                        ->label(fn () => 'Aviso '.Terms::to('instructor', 'Técnico'))
                        ->helperText(fn () => 'Horas antes de cada clase en que '.Terms::the('instructor', 'Técnico').' recibe "Hoy tenés clase…" con cuántos van. Cada usuario puede elegir sus propios avisos en la app.')
                        ->numeric()->integer()->minValue(1)->maxValue(24)->suffix('horas antes')->required(),
                ])
                ->columns(2),
            Section::make('Módulos')
                ->schema([
                    // Los módulos los habilita la plataforma (super admin); el admin solo los ve.
                    CheckboxList::make('features')
                        ->label('Módulos activos')
                        ->helperText(fn () => auth()->user()->is_super_admin ? null : 'Los módulos los habilita la plataforma.')
                        ->disabled(fn () => ! auth()->user()->is_super_admin)
                        ->options(collect(Feature::cases())->mapWithKeys(fn (Feature $feature) => [$feature->value => $feature->label()]))
                        ->columns(2),
                ]),
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['billing'] = Filament::getTenant()->billing();
        $data['class_reminder_hours'] ??= Filament::getTenant()->class_reminder_hours ?? 3;
        $data['instructor_reminder_hours'] ??= Filament::getTenant()->instructor_reminder_hours ?? 2;
        // Las palabras que usan hoy las pantallas (lo guardado o el valor por defecto).
        $data['terminology'] = array_merge(Organization::DEFAULT_TERMINOLOGY, Filament::getTenant()->terminology ?? []);
        $data['terminology_feminine'] = Filament::getTenant()->terminology_feminine ?? [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $billing = $data['billing'] ?? [];
        $data['billing'] = [
            ...$billing,
            'due_day' => (int) $billing['due_day'],
            'grace_days' => (int) $billing['grace_days'],
            'discount_order' => array_values($billing['discount_order'] ?? Organization::DEFAULT_BILLING['discount_order']),
        ];

        // Vacía = la del tipo elegido.
        $type = $data['type'] ?? null;
        $type = $type instanceof OrganizationType ? $type : OrganizationType::tryFrom((string) $type);
        $typeTerms = Templates::terminologyFor($type ?? $this->tenant->type ?? OrganizationType::Club);
        $data['terminology'] = collect(UpdateTerminology::KEYS)
            ->mapWithKeys(fn (string $key) => [$key => filled($data['terminology'][$key] ?? null) ? trim($data['terminology'][$key]) : $typeTerms[$key]])
            ->all();

        // Formas femeninas: solo las que no salen por regla (vacía o igual a la regla = se deriva).
        $feminine = collect(Organization::PERSON_TERMS)
            ->mapWithKeys(fn (string $key) => [$key => filled($data['terminology_feminine'][$key] ?? null) ? Str::ucfirst(trim($data['terminology_feminine'][$key])) : null])
            ->filter(fn (?string $word, string $key) => $word !== null && $word !== Vocabulary::feminine($data['terminology'][$key]))
            ->all();
        $data['terminology_feminine'] = $feminine === [] ? null : $feminine;

        // Cambió el vocabulario: ya decidió cómo les dicen (no se le proponen las palabras de deporte).
        $this->terminologyChanged = collect(UpdateTerminology::KEYS)
            ->contains(fn (string $key) => $data['terminology'][$key] !== $this->tenant->term($key));

        return $data;
    }

    private bool $terminologyChanged = false;

    protected function afterSave(): void
    {
        if ($this->terminologyChanged) {
            $this->tenant->forceFill(['terminology_confirmed_at' => now()])->save();
        }
    }

    protected function getRedirectUrl(): ?string
    {
        return Filament::getUrl(Filament::getTenant());
    }
}
