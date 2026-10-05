<?php

namespace App\Notifications;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use App\Support\Push\PushMessage;

/**
 * Aviso a la familia: le cobraron en efectivo desde la app o se registró su pago en el panel (con el recibo).
 * Así la familia sabe que el pago quedó registrado.
 */
class PaymentReceived extends PushNotification
{
    public string $body;

    public function __construct(Payment $payment, User $collector)
    {
        $how = $payment->method === PaymentMethod::Cash
            ? "en efectivo (cobró {$collector->name})"
            : 'por '.mb_strtolower($payment->method->label());

        $this->body = 'Recibimos tu pago de '.Money::pyg($payment->amount)->format()
            ." {$how}. Recibo N° {$payment->receiptLabel()}.";
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Pago recibido', $this->body, ['type' => 'payment_received', 'route' => '/estado-de-cuenta']);
    }

    protected function mailPose(): ?string
    {
        return 'salta';
    }
}
