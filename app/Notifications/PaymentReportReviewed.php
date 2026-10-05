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

    /**
     * @param  bool  $registrant  para quien la registró en nombre de la familia (técnico)
     */
    public function __construct(PaymentReport $report, public bool $registrant = false)
    {
        $amount = Money::pyg($report->amount)->format();
        $this->approved = $report->status === PaymentReportStatus::Approved;
        $family = $registrant ? $report->family->name : null;

        [$this->title, $this->body] = match (true) {
            $registrant && $this->approved => ['Transferencia aprobada', "Aprobamos la transferencia de {$amount} de la {$family} que registraste. Recibo N° {$report->payment->receiptLabel()}."],
            $registrant => ['Transferencia no aprobada', "No aprobamos la transferencia de {$amount} de la {$family} que registraste: {$report->rejection_reason}"],
            $this->approved => ['Pago aprobado', "Aprobamos tu pago de {$amount}. Recibo N° {$report->payment->receiptLabel()}."],
            default => ['Pago no aprobado', "No pudimos aprobar tu pago de {$amount}: {$report->rejection_reason}"],
        };
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage($this->title, $this->body, ['type' => 'payment_report_reviewed', 'route' => $this->registrant ? '/cobrar' : '/estado-de-cuenta']);
    }

    protected function mailPose(): ?string
    {
        return $this->approved ? 'salta' : null;
    }
}
