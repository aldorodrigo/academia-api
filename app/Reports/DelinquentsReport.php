<?php

namespace App\Reports;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Models\Family;
use App\Models\Guardian;
use App\Models\Organization;

/**
 * Morosos: familias con cuotas vencidas en al menos `min_months` meses distintos.
 */
class DelinquentsReport extends Report
{
    public function __construct(Organization $organization, private int $minMonths = 1)
    {
        parent::__construct($organization);
    }

    public static function key(): string
    {
        return 'morosos';
    }

    public function title(): string
    {
        return 'Morosos al '.$this->organization->today()->format('d/m/Y');
    }

    public function parameters(): array
    {
        return ['min_months' => $this->minMonths];
    }

    public function data(): array
    {
        $families = Family::query()
            ->with(['students', 'guardians'])
            ->get()
            ->map(function (Family $family) {
                $overdue = Charge::query()->notVoided()
                    ->whereIn('student_id', $family->students->modelKeys())
                    ->with(['allocations.payment', 'organization'])
                    ->orderBy('due_on')
                    ->get()
                    ->filter(fn (Charge $c) => $c->status() === ChargeStatus::Overdue);

                if ($overdue->isEmpty()) {
                    return null;
                }

                $contact = $family->guardians->first(fn (Guardian $g) => filled($g->phone)) ?? $family->guardians->first();

                return [
                    'family' => $family->name,
                    'students' => $family->students->pluck('first_name')->all(),
                    'overdue' => (int) $overdue->sum(fn (Charge $c) => $c->pendingAmount()),
                    'oldest_due_on' => $overdue->first()->due_on->toDateString(),
                    'months_overdue' => $overdue->map(fn (Charge $c) => $c->due_on->format('Y-m'))->unique()->count(),
                    'contact' => $contact ? ['name' => $contact->full_name, 'phone' => $contact->phone] : null,
                ];
            })
            ->filter(fn (?array $row) => $row !== null && $row['months_overdue'] >= $this->minMonths)
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
            ['title' => 'Familias', 'headers' => ['Familia', 'Jugadores', 'Vencido', 'Meses', 'Desde', 'Contacto', 'Teléfono'], 'money' => [2],
                'rows' => array_map(fn (array $f) => [
                    $f['family'], implode(', ', $f['students']), $f['overdue'], $f['months_overdue'],
                    date('d/m/Y', strtotime($f['oldest_due_on'])), $f['contact']['name'] ?? '', $f['contact']['phone'] ?? '',
                ], $data['families'])],
        ];
    }
}
