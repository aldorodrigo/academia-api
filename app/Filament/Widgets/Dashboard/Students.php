<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Support\Terms;
use App\Models\Student;

/**
 * Alumnos activos, altas y bajas del mes, y cada grupo con su cupo.
 */
class Students extends Card
{
    protected string $view = 'filament.widgets.dashboard.students';

    protected static ?int $sort = 4;

    /** Grupos que se listan; el resto, "y N más". */
    private const GROUPS = 8;

    public static function canView(): bool
    {
        return self::allows('viewAny', Student::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $students = $this->metrics()->students();
        $groups = collect($students['groups']);

        return [
            ...$students,
            'groups' => $groups->take(self::GROUPS)->map(fn (array $group) => [...$group, 'url' => GroupResource::getUrl('edit', ['record' => $group['id']])]),
            'more' => max(0, $groups->count() - self::GROUPS),
            'title' => ucfirst(Terms::plural('student', 'alumno')),
            'groupsUrl' => GroupResource::getUrl('index'),
            'url' => StudentResource::getUrl('index'),
            'multiplePrograms' => $groups->pluck('program')->unique()->count() > 1,
        ];
    }
}
