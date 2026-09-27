<?php

namespace App\Filament\Support;

use App\Enums\EnrollmentStatus;
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
                ->afterStateUpdated(fn (Get $get, Set $set) => $set('group_id', self::suggestedGroupId($get, $student))),
            Select::make('group_id')
                ->label(Terms::label('group', 'Categoría'))
                ->options(fn (Get $get) => self::groups($get('program_id'))
                    ->mapWithKeys(fn (Group $group) => [$group->id => "{$group->name} · {$group->program->name}"]))
                ->default(fn (Get $get) => self::suggestedGroupId($get, $student))
                ->required()
                ->searchable()
                ->live()
                ->rules(fn (Get $get, $record) => [self::notDuplicated($student, $get('season_id'), $record instanceof Enrollment ? $record : null)])
                ->helperText(fn (Get $get, $record) => self::otherEnrollmentsNote($student, $get('season_id'), $get('group_id'), $record instanceof Enrollment ? $record : null)
                    ?? 'Se sugiere según la fecha de nacimiento.'),
            Select::make('season_id')
                ->label('Temporada')
                ->options(fn () => Season::query()->orderByDesc('starts_on')->pluck('name', 'id'))
                ->default(fn () => Season::currentOrNull()?->id)
                ->required()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => $set('group_id', self::suggestedGroupId($get, $student))),
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
     * Categoría que corresponde por edad en la temporada elegida (y la disciplina, si hay varias).
     */
    public static function suggestedGroupId(Get $get, ?Student $student): ?int
    {
        $birthDate = $student?->birth_date ?? (filled($get('birth_date')) ? Carbon::parse($get('birth_date')) : null);
        $season = Season::query()->find($get('season_id')) ?? Season::currentOrNull();
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
