<?php

namespace App\Filament\Resources\Students\Schemas;

use App\Models\Student;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class StudentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // La organización la asigna el panel (tenant activo); nunca se elige a mano.
            Section::make('Datos')->columns(2)->schema([
                TextInput::make('first_name')->label('Nombre')->required()->maxLength(255),
                TextInput::make('last_name')->label('Apellido')->required()->maxLength(255),
                TextInput::make('document')->label('Documento')->maxLength(30)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, ?Student $record) => $rule
                        ->where('organization_id', filament()->getTenant()?->getKey())),
                DatePicker::make('birth_date')->label('Fecha de nacimiento')->required()->maxDate(now()),
                TextInput::make('shirt_size')->label('Talle')->maxLength(10),
                TextInput::make('position')->label('Posición')->maxLength(50),
                Select::make('family_id')
                    ->label('Familia')
                    ->relationship('family', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([TextInput::make('name')->label('Nombre')->placeholder('Familia Benítez')->required()])
                    ->helperText('Agrupa hermanos y tutores (para el estado de cuenta).'),
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
            Section::make('Ficha médica')
                ->description('Solo la ven los roles autorizados, sus tutores y sus técnicos.')
                ->relationship('medicalRecord', condition: fn (?array $state) => collect($state)->filter(fn ($value) => filled($value))->isNotEmpty())
                ->visible(fn (?Student $record) => $record
                    ? auth()->user()->can('viewMedical', $record)
                    : auth()->user()->can('ViewMedical:Student'))
                ->columns(2)
                ->collapsible()
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
}
