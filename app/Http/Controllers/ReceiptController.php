<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Support\Money;
use App\Support\Vocabulary;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recibo en PDF: lo imputado al registrar el pago y el saldo a favor que dejó
 * (no cambia aunque ese saldo se aplique después). Se abre con un link firmado y temporal (desde la app o el panel),
 * sin token: la firma protege el id.
 */
class ReceiptController extends Controller
{
    public const VALID_MINUTES = 30;

    /** El link que se manda por WhatsApp a quien no tiene la app (lo abre días después). */
    public const SHARE_DAYS = 30;

    public static function signedUrl(Payment $payment): string
    {
        return URL::temporarySignedRoute('receipts.show', now()->addMinutes(self::VALID_MINUTES), ['payment' => $payment->id]);
    }

    /**
     * Link largo (30 días) solo para mandar el recibo por WhatsApp; los demás duran `VALID_MINUTES`.
     */
    public static function shareUrl(Payment $payment): string
    {
        return URL::temporarySignedRoute('receipts.show', now()->addDays(self::SHARE_DAYS), ['payment' => $payment->id]);
    }

    public function __invoke(int $payment): Response
    {
        $payment = Payment::query()->withoutGlobalScopes()
            ->with(['organization', 'family', 'guardian', 'moneyAccount', 'allocations.charge.student'])
            ->findOrFail($payment);

        $pdf = Pdf::loadView('receipts.show', self::viewData($payment));

        return $pdf->stream("recibo-{$payment->receiptLabel()}.pdf");
    }

    /**
     * Lo que muestra el recibo. La columna de los alumnos nombra a cada uno: "Jugadora" si son todas chicas,
     * "Jugador" si hay algún varón (o la palabra del club si no se cargó el género).
     *
     * @return array<string, mixed>
     */
    public static function viewData(Payment $payment): array
    {
        $allocations = $payment->originalAllocations();

        return [
            'payment' => $payment,
            'organization' => $payment->organization,
            'allocations' => $allocations,
            'studentTerm' => $payment->organization->term('student', Vocabulary::groupGender(
                collect($allocations)->map(fn ($allocation) => $allocation->charge->student?->gender),
            )),
            'credit' => $payment->creditGenerated(),
            'money' => fn (int $amount) => Money::pyg($amount),
        ];
    }
}
