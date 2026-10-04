<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Pages\Reports;
use App\Filament\Resources\Charges\ChargeResource;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Invitations\InvitationResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Support\Terms;
use App\Models\Charge;
use App\Models\Enrollment;
use App\Models\Expense;
use App\Models\Invitation;
use App\Models\Payment;
use App\Models\Student;

/**
 * Botones a lo que más se usa, según lo que puede hacer cada uno ("Registrar pago" abre el
 * formulario directo con ?action=).
 */
class QuickActions extends Card
{
    protected string $view = 'filament.widgets.dashboard.quick-actions';

    protected static ?int $sort = -5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::buttons() !== [];
    }

    /**
     * @return list<array{label: string, icon: string, url: string}>
     */
    public static function buttons(): array
    {
        $student = Terms::singular('student', 'alumno');

        return collect([
            [self::allows('create', Student::class), Terms::gendered('student', 'alumno', 'Nuevo', 'Nueva').' '.$student, 'heroicon-o-user-plus', fn () => StudentResource::getUrl('create')],
            [self::allows('create', Payment::class), 'Registrar pago', 'heroicon-o-banknotes', fn () => PaymentResource::getUrl('index', ['action' => 'register'])],
            [self::allows('create', Expense::class), 'Registrar gasto', 'heroicon-o-receipt-percent', fn () => ExpenseResource::getUrl('index', ['action' => 'register'])],
            [self::allows('create', Charge::class), 'Nuevo cargo', 'heroicon-o-document-plus', fn () => ChargeResource::getUrl('index', ['action' => 'newCharge'])],
            [self::allows('create', Invitation::class), 'Invitar', 'heroicon-o-paper-airplane', fn () => InvitationResource::getUrl('index', ['action' => 'invite'])],
            [self::allows('viewAny', Enrollment::class), 'Inscripciones', 'heroicon-o-clipboard-document-list', fn () => EnrollmentResource::getUrl('index')],
            [self::allows('View:Reports'), 'Informes', 'heroicon-o-chart-bar', fn () => Reports::getUrl()],
        ])
            ->filter(fn (array $button) => $button[0])
            ->map(fn (array $button) => ['label' => $button[1], 'icon' => $button[2], 'url' => $button[3]()])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['buttons' => self::buttons()];
    }
}
