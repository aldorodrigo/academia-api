<?php

namespace App\Filament\Pages\Tenancy;

use App\Actions\Organizations\UpdateTerminology;
use App\Enums\AdjustmentType;
use App\Enums\Feature;
use App\Enums\OrganizationType;
use App\Filament\Support\Terms;
use App\Models\Organization;
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
        $terminology = [
            'program' => 'Programa',
            'group' => 'Grupo',
            'student' => 'Alumno',
            'instructor' => 'Instructor',
            'guardian' => 'Tutor',
            'space' => 'Cancha / sala de un lugar',
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
                        ->helperText('Horas antes de cada clase en que sale el aviso "¿Lo llevás?" a los tutores que lo pidieron. Si cae de noche, sale a las 20:00 del día anterior.')
                        ->numeric()->integer()->minValue(1)->maxValue(24)->suffix('horas antes')->required(),
                    TextInput::make('instructor_reminder_hours')
                        ->label(fn () => 'Aviso '.Terms::gendered('instructor', 'Técnico', 'al', 'a la').' '.Terms::singular('instructor', 'Técnico'))
                        ->helperText(fn () => 'Horas antes de cada clase en que '.Terms::gendered('instructor', 'Técnico', 'el', 'la').' '.Terms::singular('instructor', 'Técnico').' recibe "Hoy tenés clase…" con cuántos van. Cada usuario puede elegir sus propios avisos en la app.')
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

        $data['terminology'] = collect($data['terminology'] ?? [])
            ->map(fn (?string $value) => filled($value) ? trim($value) : null)
            ->filter()
            ->all() ?: null;

        // Cambió el vocabulario: ya decidió cómo les dicen (no se le proponen las palabras de deporte).
        $this->terminologyChanged = collect(UpdateTerminology::KEYS)
            ->contains(fn (string $key) => ($data['terminology'][$key] ?? Organization::DEFAULT_TERMINOLOGY[$key]) !== $this->tenant->term($key));

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
