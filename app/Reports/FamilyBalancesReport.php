<?php

namespace App\Reports;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Models\Family;

/**
 * Saldos por familia: lo pendiente, lo vencido y el saldo a favor.
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
     * @return list<array{family: string, students: list<string>, pending: int, overdue: int, credit: int}>
     */
    public function families(): array
    {
        return Family::query()
            ->with(['students', 'payments.allocations'])
            ->get()
            ->map(function (Family $family) {
                $charges = Charge::query()->notVoided()
                    ->whereIn('student_id', $family->students->modelKeys())
                    ->with(['allocations.payment', 'organization'])
                    ->get();

                return [
                    'family' => $family->name,
                    'students' => $family->students->pluck('first_name')->all(),
                    'pending' => (int) $charges->sum(fn (Charge $c) => $c->pendingAmount()),
                    'overdue' => (int) $charges->filter(fn (Charge $c) => $c->status() === ChargeStatus::Overdue)->sum(fn (Charge $c) => $c->pendingAmount()),
                    'credit' => (int) $family->payments->whereNull('voided_at')->sum(fn ($payment) => $payment->credit()),
                ];
            })
            ->filter(fn (array $row) => $row['pending'] > 0 || $row['credit'] > 0)
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
            ]],
            ['title' => 'Familias', 'headers' => ['Familia', 'Jugadores', 'Pendiente', 'Vencido', 'Saldo a favor'], 'money' => [2, 3, 4],
                'rows' => array_map(fn (array $f) => [$f['family'], implode(', ', $f['students']), $f['pending'], $f['overdue'], $f['credit']], $data['families'])],
        ];
    }
}
