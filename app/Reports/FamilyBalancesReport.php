<?php

namespace App\Reports;

use App\Enums\ChargeStatus;
use App\Models\Charge;

/**
 * Saldos por familia: lo pendiente, lo vencido y el saldo a favor. Las cuotas creadas por
 * adelantado cuyo período no empezó van aparte (próximas), no como pendiente.
 */
class FamilyBalancesReport extends Report
{
    public static function key(): string
    {
        return 'saldos';
    }

    public function title(): string
    {
        return 'Saldos por familia al '.$this->organization->today()->format('d/m/Y');
    }

    public function parameters(): array
    {
        return [];
    }

    /**
     * @return list<array{family: string, students: list<string>, pending: int, overdue: int, credit: int, upcoming: int}>
     */
    public function families(): array
    {
        return $this->households()
            ->map(function (array $household) {
                $charges = Charge::query()->notVoided()
                    ->whereIn('student_id', $household['students']->modelKeys())
                    ->with(['allocations.payment', 'organization'])
                    ->get();

                return [
                    'family' => $household['name'],
                    'students' => $household['students']->pluck('first_name')->all(),
                    'pending' => (int) $charges->reject(fn (Charge $c) => $c->isUpcoming())->sum(fn (Charge $c) => $c->pendingAmount()),
                    'overdue' => (int) $charges->filter(fn (Charge $c) => $c->status() === ChargeStatus::Overdue)->sum(fn (Charge $c) => $c->pendingAmount()),
                    'credit' => (int) ($household['family']?->payments->whereNull('voided_at')->sum(fn ($payment) => $payment->credit()) ?? 0),
                    'upcoming' => (int) $charges->filter(fn (Charge $c) => $c->isUpcoming())->sum(fn (Charge $c) => $c->pendingAmount()),
                ];
            })
            ->filter(fn (array $row) => $row['pending'] > 0 || $row['credit'] > 0 || $row['upcoming'] > 0)
            ->sortByDesc('pending')
            ->values()
            ->all();
    }

    public function data(): array
    {
        $families = $this->families();

        return [
            'totals' => [
                'pending' => array_sum(array_column($families, 'pending')),
                'overdue' => array_sum(array_column($families, 'overdue')),
                'credit' => array_sum(array_column($families, 'credit')),
                'upcoming' => array_sum(array_column($families, 'upcoming')),
            ],
            'families' => $families,
        ];
    }

    public function sections(): array
    {
        $data = $this->data();

        return [
            ['title' => 'Totales', 'headers' => ['', 'Monto'], 'money' => [1], 'rows' => [
                ['Pendiente de cobro', $data['totals']['pending']],
                ['Vencido', $data['totals']['overdue']],
                ['Saldo a favor', $data['totals']['credit']],
                ['Próximas cuotas', $data['totals']['upcoming']],
            ]],
            ['title' => 'Familias', 'headers' => ['Familia', 'Jugadores', 'Pendiente', 'Vencido', 'Saldo a favor', 'Próximas'], 'money' => [2, 3, 4, 5],
                'rows' => array_map(fn (array $f) => [$f['family'], implode(', ', $f['students']), $f['pending'], $f['overdue'], $f['credit'], $f['upcoming']], $data['families'])],
        ];
    }
}
