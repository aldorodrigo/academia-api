<?php

namespace App\Notifications;

use App\Models\PaymentReport;
use App\Support\Money;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Push a quienes validan: un tutor informó una transferencia.
 */
class PaymentReported extends Notification implements ShouldQueue
{
    use Queueable;

    public string $body;

    public function __construct(PaymentReport $report)
    {
        $report->loadMissing(['user', 'family']);

        $this->body = "{$report->user->name} informó una transferencia de ".Money::pyg($report->amount)->format()
            ." ({$report->family->name}).";
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
        return new PushMessage('Comprobante de pago', $this->body, ['type' => 'payment_report', 'route' => '/comprobantes']);
    }
}
