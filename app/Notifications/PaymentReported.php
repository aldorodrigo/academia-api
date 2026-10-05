<?php

namespace App\Notifications;

use App\Models\PaymentReport;
use App\Support\Money;
use App\Support\Push\PushMessage;

/**
 * Aviso a quienes validan: un tutor informó una transferencia.
 */
class PaymentReported extends PushNotification
{
    public string $body;

    public function __construct(PaymentReport $report)
    {
        $report->loadMissing(['user', 'family']);

        $verb = $report->registered_by_staff ? 'registró' : 'informó';
        $this->body = "{$report->user->name} {$verb} una transferencia de ".Money::pyg($report->amount)->format()
            ." ({$report->family->name}).";
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Comprobante de pago', $this->body, ['type' => 'payment_report', 'route' => '/comprobantes']);
    }
}
