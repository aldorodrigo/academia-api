<?php

namespace App\Http\Controllers;

use App\Models\PaymentReport;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Archivo del comprobante de transferencia (foto o PDF): link firmado y temporal
 * (app y panel), sin token. Se muestra en el navegador.
 */
class PaymentProofController extends Controller
{
    public const VALID_MINUTES = 30;

    public static function signedUrl(PaymentReport $report): string
    {
        return URL::temporarySignedRoute('payment-proofs.show', now()->addMinutes(self::VALID_MINUTES), ['report' => $report->id]);
    }

    public function __invoke(int $report): Response
    {
        $report = PaymentReport::query()->withoutGlobalScopes()->findOrFail($report);

        abort_unless(Storage::disk('local')->exists($report->proof_path), 404, 'No encontramos el comprobante.');

        return Storage::disk('local')->response($report->proof_path, $report->proof_name, [], 'inline');
    }
}
