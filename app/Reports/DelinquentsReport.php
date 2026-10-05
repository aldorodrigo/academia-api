<?php

namespace App\Reports;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Models\Guardian;
use App\Models\Organization;
use App\Support\Enrollments\Withdrawals;

/**
 * Morosos: familias (o jugadores sin familia) con cuotas vencidas en al menos
 * `min_months` meses distintos. Cada fila marca a los hijos dados de baja (`withdrawn`);
 * `$withdrawn` filtra las familias con alguno (`only`) o sin ninguno (`exclude`).
 */
class DelinquentsReport extends Report
{
    public const WITHDRAWN_FILTERS = ['only', 'exclude'];

    public function __construct(Organization $organization, private int $minMonths = 1, private ?string $withdrawn = null)
    {
        parent::__construct($organization);
        $this->withdrawn = in_array($withdrawn, self::WITHDRAWN_FILTERS, true) ? $withdrawn : null;
    }

    public static function key(): string
    {
        return 'morosos';
    }

    public function title(): string
    {
        return 'Morosos'.match ($this->withdrawn) {
            'only' => ' dados de baja',
            'exclude' => ' (sin dados de baja)',
            default => '',
        }.' al '.$this->organization->today()->format('d/m/Y');
    }

    public function parameters(): array
    {
        return ['min_months' => $this->minMonths, ...($this->withdrawn ? ['withdrawn' => $this->withdrawn] : [])];
    }

    public function data(): array
    {
        $withdrawals = Withdrawals::load();
        $families = $this->households()
            ->map(function (array $household) use ($withdrawals) {
                $overdue = Charge::query()->notVoided()
                    ->whereIn('student_id', $household['students']->modelKeys())
                    ->with(['allocations.payment', 'organization'])
                    ->orderBy('due_on')
                    ->get()
                    ->filter(fn (Charge $c) => $c->status() === ChargeStatus::Overdue);

                if ($overdue->isEmpty()) {
                    return null;
                }

                $contact = $household['guardians']->first(fn (Guardian $g) => filled($g->phone)) ?? $household['guardians']->first();

                return [
                    'family' => $household['name'],
                    'students' => $household['students']->pluck('first_name')->all(),
                    'overdue' => (int) $overdue->sum(fn (Charge $c) => $c->pendingAmount()),
                    'oldest_due_on' => $overdue->first()->due_on->toDateString(),
                    'months_overdue' => $overdue->map(fn (Charge $c) => $c->due_on->format('Y-m'))->unique()->count(),
                    'contact' => $contact ? ['name' => $contact->full_name, 'phone' => $contact->phone_display] : null,
                    'withdrawn' => $withdrawals->for($household['students']),
                ];
            })
            ->filter(fn (?array $row) => $row !== null && $row['months_overdue'] >= $this->minMonths)
            ->filter(fn (array $row) => match ($this->withdrawn) {
                'only' => $row['withdrawn'] !== [],
                'exclude' => $row['withdrawn'] === [],
                default => true,
            })
            ->sortByDesc('overdue')
            ->values()
            ->all();

        return [
            'total' => array_sum(array_column($families, 'overdue')),
            'families' => $families,
        ];
    }

    public function sections(): array
    {
        $data = $this->data();

        return [
            ['title' => 'Total vencido', 'headers' => ['', 'Monto'], 'money' => [1], 'rows' => [
                [count($data['families']).' familias', $data['total']],
            ]],
            ['title' => 'Familias', 'headers' => ['Familia', 'Jugadores', 'Vencido', 'Meses', 'Desde', 'Contacto', 'Teléfono', 'Dados de baja'], 'money' => [2],
                'rows' => array_map(fn (array $f) => [
                    $f['family'], implode(', ', $f['students']), $f['overdue'], $f['months_overdue'],
                    date('d/m/Y', strtotime($f['oldest_due_on'])), $f['contact']['name'] ?? '', $f['contact']['phone'] ?? '',
                    Withdrawals::describe($f['withdrawn']),
                ], $data['families'])],
        ];
    }
}
