<?php

namespace App\Filament\Resources\Students\Schemas;

use App\Enums\GuardianRelationship;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Support\EnrollmentForm;
use App\Filament\Support\Terms;
use App\Models\Guardian;
use App\Models\Student;
use App\Support\Phone;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Unique;

class StudentForm
{
    public const ALREADY_LOADED = 'Ya está cargado: inscribilo desde su ficha.';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // Al crear: si el chico ya está cargado, se lo inscribe desde su ficha (no se duplica).
            Callout::make(fn (Get $get) => ($existing = self::existing($get))
                ? "{$existing->full_name} ya está cargado".($existing->currentEnrollments->isEmpty() ? '.' : ' ('.self::enrollmentsSummary($existing).').')
                : null)
                ->description('Para inscribirlo en otra disciplina o en la nueva temporada, hacelo desde su ficha.')
                ->warning()
                ->visible(fn (Get $get, string $operation) => $operation === 'create' && self::existing($get) !== null)
                ->actions([
                    Action::make('enrollExisting')
                        ->label('Inscribirlo')
                        ->url(fn ($livewire) => ($existing = self::existingFrom($livewire->data ?? []))
                            ? EditStudent::getUrl(['record' => $existing, 'action' => 'enroll'])
                            : null),
                ])
                ->columnSpanFull(),
            // La organización la asigna el panel (tenant activo); nunca se elige a mano.
            Section::make('Datos')->columnSpanFull()->columns(3)->schema([
                TextInput::make('first_name')->label('Nombre')->required()->maxLength(255)
                    ->live(onBlur: true)
                    ->rules(fn (Get $get, string $operation) => [function (string $attribute, mixed $value, Closure $fail) use ($get, $operation) {
                        if ($operation === 'create' && self::existing($get) !== null) {
                            $fail(self::ALREADY_LOADED);
                        }
                    }]),
                TextInput::make('last_name')->label('Apellido')->required()->maxLength(255)->live(onBlur: true),
                // Obligatorio al cargar uno nuevo (evita duplicados); los que ya estaban sin documento se siguen editando.
                TextInput::make('document')->label('Documento')->maxLength(30)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->live(onBlur: true)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, ?Student $record) => $rule
                        ->where('organization_id', filament()->getTenant()?->getKey())->whereNull('deleted_at'))
                    ->validationMessages(['unique' => fn (string $operation) => $operation === 'create'
                        ? self::ALREADY_LOADED
                        : 'Ya hay otro jugador con este documento.']),
                DatePicker::make('birth_date')
                    ->label('Fecha de nacimiento')
                    ->required()
                    ->maxDate(now())
                    ->live(onBlur: true)
                    // Al crear, sugiere la categoría que corresponde por edad.
                    ->afterStateUpdated(function (Get $get, Set $set, string $operation) {
                        if ($operation === 'create' && ($groupId = EnrollmentForm::suggestedGroupId($get, null))) {
                            $set('group_id', $groupId);
                        }
                    }),
                TextInput::make('shirt_size')->label('Talle')->maxLength(10),
                TextInput::make('position')->label('Posición')->maxLength(50),
                Select::make('user_id')
                    ->label('Cuenta propia (alumno adulto)')
                    ->relationship('user', 'name', fn ($query) => $query->whereHas(
                        'memberships',
                        fn ($memberships) => $memberships->where('organization_id', filament()->getTenant()?->getKey()),
                    ))
                    ->searchable()
                    ->helperText('Solo para alumnos adultos que son su propio responsable.'),
                Textarea::make('notes')->label('Notas')->columnSpanFull(),
            ]),
            ...self::enrollmentAndGuardians(),
            Section::make('Ficha médica')
                ->description('Solo la ven los roles autorizados, sus tutores y sus técnicos.')
                ->relationship('medicalRecord', condition: fn (?array $state) => collect($state)->filter(fn ($value) => filled($value))->isNotEmpty())
                ->visible(fn (?Student $record) => $record
                    ? auth()->user()->can('viewMedical', $record)
                    : auth()->user()->can('ViewMedical:Student'))
                ->columnSpanFull()
                ->columns(2)
                ->collapsible()
                ->collapsed(fn (string $operation) => $operation === 'create')
                ->schema([
                    Select::make('blood_type')->label('Grupo sanguíneo')
                        ->options(array_combine($types = ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'], $types)),
                    DatePicker::make('fit_until')->label('Apto médico vigente hasta'),
                    Textarea::make('allergies')->label('Alergias'),
                    Textarea::make('conditions')->label('Condiciones'),
                    Textarea::make('medications')->label('Medicación'),
                    TextInput::make('emergency_contact_name')->label('Contacto de emergencia'),
                    TextInput::make('emergency_contact_phone')->label('Teléfono de emergencia')->tel(),
                ]),
        ]);
    }

    /**
     * Solo al crear: el jugador se inscribe y se cargan sus tutores en el mismo paso.
     *
     * @return list<Section>
     */
    private static function enrollmentAndGuardians(): array
    {
        return [
            Section::make('Inscripción')->visibleOn('create')->columnSpanFull()->columns(4)
                ->schema(EnrollmentForm::fields(details: false)),
            Section::make(ucfirst(Terms::plural('guardian', 'Tutor')))
                ->description('Con el celular (WhatsApp) o el correo, después de crear los invitás a la app desde la ficha del jugador: al aceptar ven a sus hijos.')
                ->visibleOn('create')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('guardians')
                        ->hiddenLabel()
                        ->columns(3)
                        ->defaultItems(1)
                        ->addActionLabel('Agregar otro tutor')
                        // Un menor necesita al menos un tutor; un adulto puede ser su propio responsable.
                        ->minItems(fn (Get $get) => self::isMinor($get('birth_date')) ? 1 : 0)
                        ->validationMessages(['min' => 'El jugador es menor de edad: cargá al menos un tutor.'])
                        ->schema([
                            TextInput::make('phone')
                                ->label('Celular (WhatsApp)')
                                ->tel()
                                ->maxLength(30)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (?string $state, Set $set) => self::fillExistingGuardian(self::existingGuardian(phone: $state), $set))
                                ->helperText(fn (?string $state) => self::existingGuardianNote(self::existingGuardian(phone: $state))),
                            TextInput::make('email')
                                ->label('Correo')
                                ->email()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (?string $state, Set $set) => self::fillExistingGuardian(self::existingGuardian(email: $state), $set))
                                ->helperText(fn (?string $state) => self::existingGuardianNote(self::existingGuardian(email: $state))),
                            Select::make('relationship')
                                ->label('Parentesco')
                                ->options(GuardianRelationship::class)
                                ->default(GuardianRelationship::Mother->value)
                                ->required(),
                            TextInput::make('first_name')->label('Nombre')->required()->maxLength(255),
                            TextInput::make('last_name')->label('Apellido')->required()->maxLength(255),
                        ]),
                ]),
        ];
    }

    private static function existing(Get $get): ?Student
    {
        return self::existingFrom([
            'document' => $get('document'),
            'first_name' => $get('first_name'),
            'last_name' => $get('last_name'),
            'birth_date' => $get('birth_date'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function existingFrom(array $data): ?Student
    {
        return Student::findExisting(
            $data['document'] ?? null,
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
            $data['birth_date'] ?? null,
        )?->loadMissing('currentEnrollments.group.program', 'currentEnrollments.season');
    }

    private static function enrollmentsSummary(Student $student): string
    {
        return $student->currentEnrollments
            ->map(fn ($e) => "{$e->group->name} · {$e->group->program->name} · {$e->season->name}")
            ->join(', ');
    }

    private static function isMinor(?string $birthDate): bool
    {
        return $birthDate === null || Carbon::parse($birthDate)->age < Student::ADULT_AGE;
    }

    /**
     * Tutor ya cargado (ej. padre de un hermano), por su celular o su correo.
     */
    private static function existingGuardian(?string $phone = null, ?string $email = null): ?Guardian
    {
        $phone = Phone::normalize($phone);
        $email = filled($email) ? mb_strtolower(trim($email)) : null;

        return match (true) {
            $phone !== null => Guardian::query()->with('students')->where('phone', $phone)->first(),
            $email !== null => Guardian::query()->with('students')->where('email', $email)->first(),
            default => null,
        };
    }

    /**
     * Se completan sus datos y se reutiliza.
     */
    private static function fillExistingGuardian(?Guardian $guardian, Set $set): void
    {
        if ($guardian) {
            $set('first_name', $guardian->first_name);
            $set('last_name', $guardian->last_name);

            if (filled($guardian->phone)) {
                $set('phone', $guardian->phone_display);
            }

            if (filled($guardian->email)) {
                $set('email', $guardian->email);
            }
        }
    }

    private static function existingGuardianNote(?Guardian $guardian): ?string
    {
        if ($guardian === null) {
            return null;
        }

        $children = $guardian->students->pluck('first_name')->join(', ', ' y ');
        $note = $children === '' ? 'Ya está cargado.' : "Ya está cargado: tutor de {$children}.";

        return $guardian->hasAccount() ? "{$note} Ya usa la app." : $note;
    }
}
