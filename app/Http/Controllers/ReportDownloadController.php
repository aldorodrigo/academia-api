<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Reports\BalanceReport;
use App\Reports\DelinquentsReport;
use App\Reports\FamilyBalancesReport;
use App\Reports\Report;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descarga de informes en PDF o Excel por link firmado y temporal (app y panel).
 * La organización y los parámetros van dentro de la firma.
 */
class ReportDownloadController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current, string $report, string $format): Response
    {
        $organization = Organization::query()->findOrFail($request->integer('organization'));

        return $current->run($organization, function (Organization $organization) use ($request, $report, $format) {
            $instance = self::make($organization, $report, $request->query());

            return $format === 'pdf' ? $instance->pdf() : $instance->xlsx();
        });
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function make(Organization $organization, string $report, array $parameters): Report
    {
        return match ($report) {
            BalanceReport::key() => new BalanceReport(
                $organization,
                CarbonImmutable::parse($parameters['from']),
                CarbonImmutable::parse($parameters['to']),
            ),
            FamilyBalancesReport::key() => new FamilyBalancesReport($organization),
            DelinquentsReport::key() => new DelinquentsReport($organization, (int) ($parameters['min_months'] ?? 1)),
            default => abort(404),
        };
    }
}
