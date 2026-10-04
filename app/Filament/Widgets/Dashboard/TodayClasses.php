<?php

namespace App\Filament\Widgets\Dashboard;

use App\Actions\Attendance\AttendanceAccess;
use App\Actions\Attendance\ResolveClassSessions;
use App\Filament\Resources\Groups\GroupResource;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\Schedule;

/**
 * "Hoy": las clases del día con su estado (asistencia tomada, falta tomarla, suspendida). El
 * técnico ve las de sus grupos; quien toma asistencia en todos, todas.
 */
class TodayClasses extends Card
{
    protected string $view = 'filament.widgets.dashboard.today-classes';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return self::allows('viewAny', Group::class) || (auth()->user() !== null && AttendanceAccess::canTakeAny(auth()->user()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $sessions = app(ResolveClassSessions::class)->forDate(AttendanceAccess::groups(auth()->user())->get(), $this->organization()->today());

        $classes = $sessions->map(function (ClassSession $class) {
            $counts = $class->isOff() ? null : $class->counts();
            $notGoing = $counts['not_going'] ?? 0;

            [$status, $color] = match (true) {
                $class->isSuspended() => ['Suspendida', 'gray'],
                $class->isRescheduled() => ['Reprogramada', 'gray'],
                $class->isAttendanceTaken() => ["Vinieron {$counts['present']} de {$counts['enrolled']}", 'success'],
                $class->hasStarted() => ['Falta la asistencia', 'warning'],
                $notGoing > 0 => [$notGoing === 1 ? '1 avisó que no va' : "{$notGoing} avisaron que no van", 'gray'],
                default => [$counts['enrolled'] === 1 ? '1 inscripto' : "{$counts['enrolled']} inscriptos", 'gray'],
            };

            return [
                'time' => Schedule::time($class->starts_at).'–'.Schedule::time($class->ends_at),
                'group' => $class->group->name,
                'venue' => $class->venue?->label,
                'makeup' => $class->rescheduledFrom !== null,
                'status' => $status,
                'color' => $color,
                'url' => GroupResource::getUrl('edit', ['record' => $class->group_id]),
            ];
        });

        return [
            'classes' => $classes,
            'missing' => $classes->where('color', 'warning')->count(),
        ];
    }
}
