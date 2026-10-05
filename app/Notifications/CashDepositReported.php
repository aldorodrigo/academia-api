<?php

namespace App\Notifications;

use App\Models\CashDeposit;
use App\Support\Money;
use App\Support\Push\PushMessage;

/**
 * Aviso a quienes validan: alguien que cobra en efectivo informó un depósito para confirmar.
 */
class CashDepositReported extends PushNotification
{
    public string $body;

    public function __construct(CashDeposit $deposit)
    {
        $deposit->loadMissing(['user', 'toAccount']);

        $this->body = "{$deposit->user->name} depositó ".Money::pyg($deposit->amount)->format()
            ." en {$deposit->toAccount->name}. Confirmalo cuando lo veas.";
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Depósito de efectivo', $this->body, ['type' => 'cash_deposit', 'route' => '/efectivo']);
    }
}
