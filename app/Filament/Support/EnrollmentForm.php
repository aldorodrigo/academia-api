<?php

namespace App\Filament\Support;

use App\Enums\EnrollmentStatus;
use App\Enums\MidPeriod;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Program;
use App\Models\Season;
use App\Models\Student;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Campos de una inscripción, iguales en "Nuevo jugador", la acción Inscribir y la
 * pestaña Inscripciones: disciplina (si hay varias), categoría sugerida por edad,
 * temporada y estado.
 */
class EnrollmentForm
{
    /**
     * @param  Student|null  $student  null en "Nuevo jugador": la fecha de nacimiento sale del formulario.
     * @param  bool  $details  fecha de inscripción y notas (no en "Nuevo jugador").
     * @return list<mixed>
     */
    public static function fields(?Student $student = null, bool $details = true): array
    {
        return [
            Select::make('program_id')
                ->label(Terms::label('program', 'Disciplina'))
                ->options(fn () => Program::query()->orderBy('name')->pluck('name', 'id'))
                ->visible(fn () => Program::query()->count() > 1)
                ->dehydrated(false)
                ->live()
                ->afterStateHydrated(fn (Select $component, $record) => $component->state($record instanceof Enrollment ? $record->group?->program_id : null))
                ->afterStateUpdated(function (Get $get, Set $set) use ($student) {
                    self::syncSeason($get, $set);
                    $set('group_id', self::suggestedGroupId($get, $student));
                }),
            Select::make('group_id')
                ->label(Terms::label('group', 'Categoría'))
                ->options(fn (Get $get) => self::groups($get('program_id'))
                    ->mapWithKeys(fn (Group $group) => [$group->id => "{$group->name} · {$group->program->name}"]))
                ->default(fn (Get $get) => self::suggestedGroupId($get, $student))
                ->required()
                ->searchable()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::syncSeason($get, $set))
                ->rules(fn (Get $get, $record) => [self::notDuplicated($student, $get('season_id'), $record instanceof Enrollment ? $record : null)])
                ->helperText(fn (Get $get, $record) => self::otherEnrollmentsNote($student, $get('season_id'), $get('group_id'), $record instanceof Enrollment ? $record : null)
                    ?? 'Se sugiere según la fecha de nacimiento.'),
            // Vigentes o próximas de la disciplina; si hay una sola, se elige sola y no se muestra.
            Select::make('season_id')
                ->label('Temporada')
                ->options(fn (Get $get, $record) => self::seasons($get, $record instanceof Enrollment ? $record : null)->pluck('name', 'id'))
                ->default(fn (Get $get) => Season::defaultFor(self::programId($get))?->id)
                ->visible(fn (Get $get, $record) => self::seasons($get, $record instanceof Enrollment ? $record : null)->count() > 1)
                ->dehydratedWhenHidden()
                ->required()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => $set('group_id', self::suggestedGroupId($get, $student)))
                ->validationMessages(['required' => 'No hay una temporada vigente o próxima para esa disciplina: creala en Temporadas.']),
            // Solo si el período ya empezó (con el efecto en vivo).
            Select::make('mid_period')
                ->label('Del período en curso se cobra')
                ->options(MidPeriod::class)
                ->default(fn (Get $get) => Season::query()->find($get('season_id'))?->mid_period?->value)
                ->visible(fn (Get $get, $record) => ! $record instanceof Enrollment && MidPeriodPreview::applies($get))
                ->live()
                ->helperText(fn (Get $get) => MidPeriodPreview::text($get)),
            Select::make('status')
                ->label('Estado')
                // Al inscribir: activo, becado o pendiente; al editar, todos.
                ->options(fn ($record) => $record instanceof Enrollment
                    ? EnrollmentStatus::class
                    : collect([EnrollmentStatus::Active, EnrollmentStatus::Scholarship, EnrollmentStatus::Pending])
                        ->mapWithKeys(fn (EnrollmentStatus $status) => [$status->value => $status->label()])->all())
                ->default(EnrollmentStatus::Active->value)
                ->required(),
            ...($details ? [
                DatePicker::make('enrolled_on')->label('Fecha de inscripción')->default(now()),
                Textarea::make('notes')->label('Notas')->columnSpanFull(),
            ] : []),
        ];
    }

    /**
     * Disciplina elegida: la del campo, la de la categoría o la única que hay.
     */
    public static function programId(Get $get): ?int
    {
        if (filled($get('program_id'))) {
            return (int) $get('program_id');
        }

        if (filled($get('group_id'))) {
            return Group::query()->whereKey($get('group_id'))->value('program_id');
        }

        $programs = Program::query()->pluck('id');

        return $programs->count() === 1 ? $programs->first() : null;
    }

    /**
     * Temporadas vigentes o próximas de la disciplina (y la de la inscripción que se edita).
     *
     * @return Collection<int, Season>
     */
    public static function seasons(Get $get, ?Enrollment $editing = null): Collection
    {
        $programId = self::programId($get);

        return Season::query()
            ->where(fn ($query) => $query
                ->where(fn ($open) => $open->open()->when($programId, fn ($q) => $q->forProgram($programId)))
                ->when($editing, fn ($q) => $q->orWhereKey($editing->season_id)))
            ->orderByDesc('starts_on')
            ->get();
    }

    /**
     * Si la temporada elegida no es de la disciplina, se cambia por la que corresponde.
     */
    private static function syncSeason(Get $get, Set $set): void
    {
        if (! self::seasons($get)->contains('id', (int) $get('season_id'))) {
            $set('season_id', Season::defaultFor(self::programId($get))?->id);
        }

        $set('mid_period', Season::query()->find($get('season_id'))?->mid_period?->value);
    }

    /**
     * Categoría que corresponde por edad en la temporada elegida (y la disciplina, si hay varias).
     */
    public static function suggestedGroupId(Get $get, ?Student $student): ?int
    {
        $birthDate = $student?->birth_date ?? (filled($get('birth_date')) ? Carbon::parse($get('birth_date')) : null);
        $season = Season::query()->find($get('season_id')) ?? Season::defaultFor(filled($get('program_id')) ? (int) $get('program_id') : null);
        $program = filled($get('program_id')) ? Program::query()->find($get('program_id')) : null;

        return $birthDate && $season ? Group::suggestFor($birthDate, $season, $program)?->id : null;
    }

    /**
     * @return Collection<int, Group>
     */
    private static function groups(mixed $programId): Collection
    {
        return Group::query()
            ->with('program')
            ->where('is_active', true)
            ->when(filled($programId), fn ($query) => $query->where('program_id', $programId))
            ->orderBy('name')
            ->get();
    }

    /**
     * Una inscripción por jugador, categoría y temporada.
     */
    private static function notDuplicated(?Student $student, mixed $seasonId, ?Enrollment $editing): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($student, $seasonId, $editing) {
            $exists = $student !== null && Enrollment::query()
                ->where('student_id', $student->id)
                ->where('group_id', $value)
                ->where('season_id', $seasonId)
                ->when($editing, fn ($query) => $query->whereKeyNot($editing->id))
                ->exists();

            if ($exists) {
                $fail('Ya está inscripto en ese grupo esta temporada.');
            }
        };
    }

    /**
     * Aviso (no bloquea) si ya está inscripto esa temporada en otra categoría.
     */
    public static function otherEnrollmentsNote(?Student $student, mixed $seasonId, mixed $groupId, ?Enrollment $editing = null): ?string
    {
        if ($student === null || blank($seasonId)) {
            return null;
        }

        $others = Enrollment::query()
            ->with('group.program')
            ->where('student_id', $student->id)
            ->where('season_id', $seasonId)
            ->when(filled($groupId), fn ($query) => $query->where('group_id', '!=', $groupId))
            ->when($editing, fn ($query) => $query->whereKeyNot($editing->id))
            ->get();

        return $others->isEmpty() ? null
            : 'También está en '.$others->map(fn (Enrollment $e) => "{$e->group->name} · {$e->group->program->name}")->join(', ', ' y ').'.';
    }
}
