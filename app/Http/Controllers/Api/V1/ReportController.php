<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Reports\BalanceReport;
use App\Reports\DelinquentsReport;
use App\Reports\FamilyBalancesReport;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Informes para la comisión (permiso "Ver informes"), con links firmados a PDF y Excel.
 */
class ReportController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function balance(Request $request): JsonResponse
    {
        $this->authorizeReports($request);
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $today = $this->current->get()->today();
        $report = new BalanceReport(
            $this->current->get(),
            $request->filled('from') ? CarbonImmutable::parse($request->input('from')) : $today->startOfMonth(),
            $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : $today->endOfMonth()->startOfDay(),
        );

        return response()->json(['data' => [...$report->data(), ...$report->links()]]);
    }

    public function balances(Request $request): JsonResponse
    {
        $this->authorizeReports($request);
        $report = new FamilyBalancesReport($this->current->get());

        return response()->json(['data' => [...$report->data(), ...$report->links()]]);
    }

    public function delinquents(Request $request): JsonResponse
    {
        $this->authorizeReports($request);
        $request->validate([
            'min_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'withdrawn' => ['nullable', 'in:'.implode(',', DelinquentsReport::WITHDRAWN_FILTERS)],
        ]);
        $report = new DelinquentsReport($this->current->get(), (int) $request->input('min_months', 1), $request->input('withdrawn'));

        return response()->json(['data' => [...$report->data(), ...$report->links()]]);
    }

    private function authorizeReports(Request $request): void
    {
        abort_unless($request->user()->can('View:Reports'), 403, 'No tenés acceso a los informes.');
    }
}
