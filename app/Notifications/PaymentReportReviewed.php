<?php

namespace App\Notifications;

use App\Enums\PaymentReportStatus;
use App\Models\PaymentReport;
use App\Support\Money;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Push al tutor: su comprobante se aprobó (con el recibo) o se rechazó (con el motivo).
 */
class PaymentReportReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public string $title;

    public string $body;

    public function __construct(PaymentReport $report)
    {
        $amount = Money::pyg($report->amount)->format();

        [$this->title, $this->body] = $report->status === PaymentReportStatus::Approved
            ? ['Pago aprobado', "Aprobamos tu pago de {$amount}. Recibo N° {$report->payment->receiptLabel()}."]
            : ['Pago no aprobado', "No pudimos aprobar tu pago de {$amount}: {$report->rejection_reason}"];
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['push'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage($this->title, $this->body, ['type' => 'payment_report_reviewed', 'route' => '/estado-de-cuenta']);
    }
}
