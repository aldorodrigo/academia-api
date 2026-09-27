<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recibo en PDF. Se abre con un link firmado y temporal (desde la app o el panel),
 * sin token: la firma protege el id.
 */
class ReceiptController extends Controller
{
    public const VALID_MINUTES = 30;

    public static function signedUrl(Payment $payment): string
    {
        return URL::temporarySignedRoute('receipts.show', now()->addMinutes(self::VALID_MINUTES), ['payment' => $payment->id]);
    }

    public function __invoke(int $payment): Response
    {
        $payment = Payment::query()->withoutGlobalScopes()
            ->with(['organization', 'family', 'guardian', 'moneyAccount', 'allocations.charge.student'])
            ->findOrFail($payment);

        $pdf = Pdf::loadView('receipts.show', [
            'payment' => $payment,
            'organization' => $payment->organization,
            'credit' => $payment->credit(),
            'money' => fn (int $amount) => Money::pyg($amount),
        ]);

        return $pdf->stream("recibo-{$payment->receiptLabel()}.pdf");
    }
}
