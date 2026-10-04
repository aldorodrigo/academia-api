<?php

namespace App\Notifications;

use App\Enums\PaymentReportStatus;
use App\Models\PaymentReport;
use App\Support\Money;
use App\Support\Push\PushMessage;

/**
 * Aviso al tutor: su comprobante se aprobó (con el recibo) o se rechazó (con el motivo).
 */
class PaymentReportReviewed extends PushNotification
{
    public string $title;

    public string $body;

    public bool $approved;

    public function __construct(PaymentReport $report)
    {
        $amount = Money::pyg($report->amount)->format();
        $this->approved = $report->status === PaymentReportStatus::Approved;

        [$this->title, $this->body] = $this->approved
            ? ['Pago aprobado', "Aprobamos tu pago de {$amount}. Recibo N° {$report->payment->receiptLabel()}."]
            : ['Pago no aprobado', "No pudimos aprobar tu pago de {$amount}: {$report->rejection_reason}"];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage($this->title, $this->body, ['type' => 'payment_report_reviewed', 'route' => '/estado-de-cuenta']);
    }

    protected function mailPose(): ?string
    {
        return $this->approved ? 'salta' : null;
    }
}
