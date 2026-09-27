<?php

namespace App\Filament\Resources\Groups\Schemas;

use App\Enums\GroupCriterion;
use App\Enums\OrganizationRole;
use App\Filament\Support\Terms;
use App\Models\Group;
use App\Models\Program;
use App\Models\Schedule;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class GroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos')->columns(2)->schema([
                // Las disciplinas se crean y editan acá (sin menú propio). Con una sola, no se pregunta.
                Select::make('program_id')
                    ->label(Terms::label('program', 'Disciplina'))
                    ->relationship('program', 'name')
                    ->required()
                    ->live()
                    ->preload()
                    ->default(fn () => self::onlyProgram()?->id)
                    ->hidden(fn (?Group $record) => self::onlyProgram() !== null && ($record === null || $record->program_id === self::onlyProgram()->id))
                    ->dehydratedWhenHidden()
                    ->createOptionForm(self::programFields())
                    ->editOptionForm(self::programFields()),
                TextInput::make('name')->label('Nombre')->placeholder('Sub-10')->required()->maxLength(255),
                Grid::make(2)
                    ->visible(fn (Get $get) => self::criterion($get) === GroupCriterion::BirthYear)
                    ->schema([
                        TextInput::make('min_age')->label('Edad desde')->numeric()->minValue(3)->maxValue(99),
                        TextInput::make('max_age')->label('Edad hasta')->numeric()->minValue(3)->maxValue(99)
                            ->gte('min_age')
                            ->helperText('Edad que se cumple en el año de la temporada (Sub-10 → hasta 10).'),
                    ]),
                TextInput::make('level')
                    ->label('Nivel')
                    ->placeholder('Inicial')
                    ->visible(fn (Get $get) => self::criterion($get) === GroupCriterion::Level),
                TextInput::make('capacity')->label('Cupo')->numeric()->minValue(1),
                Toggle::make('is_active')->label('Activo')->default(true),
                Select::make('instructors')
                    ->label(ucfirst(Terms::plural('instructor', 'Técnico')))
                    ->relationship('instructors', 'name', fn (Builder $query) => self::instructorsQuery($query))
                    ->multiple()
                    ->preload()
                    ->helperText('Miembros con el rol de instructor.')
                    ->columnSpanFull(),
            ]),
            Section::make('Horarios')->schema([
                Repeater::make('schedules')
                    ->hiddenLabel()
                    ->relationship()
                    ->columns(4)
                    ->defaultItems(0)
                    ->addActionLabel('Agregar horario')
                    ->schema([
                        Select::make('weekday')->label('Día')->options(Schedule::WEEKDAYS)->required(),
                        TimePicker::make('starts_at')->label('Desde')->seconds(false)->required(),
                        TimePicker::make('ends_at')->label('Hasta')->seconds(false)->required()->after('starts_at'),
                        // Sedes y canchas se crean y editan acá (sin menú propio).
                        Select::make('venue_id')
                            ->label('Cancha')
                            ->relationship('venue', 'name')
                            ->preload()
                            ->createOptionForm(self::venueFields())
                            ->editOptionForm(self::venueFields()),
                    ]),
            ]),
        ]);
    }

    private static function criterion(Get $get): ?GroupCriterion
    {
        return Program::query()->find($get('program_id') ?? self::onlyProgram()?->id)?->group_criterion;
    }

    /**
     * La única disciplina de la organización, o null si hay ninguna o varias.
     */
    public static function onlyProgram(): ?Program
    {
        $programs = Program::query()->limit(2)->get();

        return $programs->count() === 1 ? $programs->first() : null;
    }

    /**
     * @return array<int, mixed>
     */
    public static function programFields(): array
    {
        return [
            TextInput::make('name')->label('Nombre')->placeholder('Fútbol')->required()->maxLength(255),
            Select::make('group_criterion')
                ->label('Criterio de '.Terms::plural('group', 'Categoría'))
                ->options(GroupCriterion::class)
                ->default(GroupCriterion::BirthYear)
                ->required()
                ->helperText('Por año de nacimiento (Sub-10, Sub-12…) o por nivel (Inicial, Avanzado…).'),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function venueFields(): array
    {
        return [
            TextInput::make('name')->label('Nombre')->placeholder('Cancha 1')->required()->maxLength(255),
            TextInput::make('address')->label('Dirección')->maxLength(255),
        ];
    }

    /**
     * Miembros de la organización con el rol de instructor vigente.
     */
    private static function instructorsQuery(Builder $query): Builder
    {
        $organization = Filament::getTenant();

        return $query->whereHas('roleAssignments', fn (Builder $assignments) => $assignments
            ->where('organization_id', $organization?->getKey())
            ->whereNull('ended_at')
            ->whereHas('role', fn (Builder $role) => $role->where('name', OrganizationRole::Instructor->value)));
    }
}
