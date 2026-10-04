<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Guardians\GuardianResource;
use App\Filament\Resources\Invitations\InvitationResource;
use App\Filament\Support\Terms;
use App\Models\Organization;
use App\Support\Dashboard\Metrics;
use Filament\Facades\Filament;

/**
 * "Para hacer": lo que está esperando a alguien (invitaciones, grupos sin técnico o sin horario,
 * tutores sin la app, gastos por confirmar). Cada cosa, solo para quien puede resolverla.
 */
class Pending extends Card
{
    protected string $view = 'filament.widgets.dashboard.pending';

    protected static ?int $sort = 6;

    public static function canView(): bool
    {
        return self::items() !== [];
    }

    /**
     * @return list<array{text: string, url: string, color: string}>
     */
    public static function items(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization || auth()->user() === null) {
            return [];
        }

        $count = Metrics::for($tenant)->pending();
        $n = fn (int $value, string $one, string $many) => $value === 1 ? "1 {$one}" : "{$value} {$many}";
        $group = Terms::singular('group', 'grupo');
        $groups = Terms::plural('group', 'grupo');
        $instructor = Terms::singular('instructor', 'técnico');
        $guardians = Terms::plural('guardian', 'tutor');

        return collect([
            [self::allows('Update:Enrollment'), $count['pending_enrollments'], $n($count['pending_enrollments'], 'inscripción pendiente', 'inscripciones pendientes'), fn () => EnrollmentResource::getUrl('index'), 'warning'],
            [self::allows('Update:Group'), $count['groups_without_schedule'], $n($count['groups_without_schedule'], "{$group} sin horario", "{$groups} sin horario"), fn () => GroupResource::getUrl('index'), 'warning'],
            [self::allows('Update:Group'), $count['groups_without_instructor'], $n($count['groups_without_instructor'], "{$group} sin {$instructor}", "{$groups} sin {$instructor}"), fn () => GroupResource::getUrl('index'), 'gray'],
            [self::allows('Create:Invitation'), $count['expired_invitations'], $n($count['expired_invitations'], 'invitación vencida: reenviá el link', 'invitaciones vencidas: reenviá el link'), fn () => InvitationResource::getUrl('index'), 'warning'],
            [self::allows('Create:Invitation'), $count['invitations'], $n($count['invitations'], 'invitación sin aceptar', 'invitaciones sin aceptar'), fn () => InvitationResource::getUrl('index'), 'gray'],
            [self::allows('Create:Invitation'), $count['guardians_without_app'], $n($count['guardians_without_app'], Terms::singular('guardian', 'tutor').' sin la app: invitalo', "{$guardians} sin la app: invitalos"), fn () => GuardianResource::getUrl('index'), 'gray'],
            [self::allows('Update:Expense'), $count['pending_expenses'], $n($count['pending_expenses'], 'gasto por confirmar', 'gastos por confirmar'), fn () => ExpenseResource::getUrl('index'), 'gray'],
        ])
            ->filter(fn (array $item) => $item[0] && $item[1] > 0)
            ->map(fn (array $item) => ['text' => $item[2], 'url' => $item[3](), 'color' => $item[4]])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['items' => self::items()];
    }
}
