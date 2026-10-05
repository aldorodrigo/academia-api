<?php

namespace App\Notifications;

use App\Enums\CashDepositStatus;
use App\Models\CashDeposit;
use App\Support\Money;
use App\Support\Push\PushMessage;

/**
 * Aviso a quien depositó: se confirmó (salió de su caja) o se rechazó (con el motivo).
 */
class CashDepositReviewed extends PushNotification
{
    public string $title;

    public string $body;

    public function __construct(CashDeposit $deposit)
    {
        $deposit->loadMissing('toAccount');
        $amount = Money::pyg($deposit->amount)->format();

        [$this->title, $this->body] = $deposit->status === CashDepositStatus::Confirmed
            ? ['Depósito confirmado', "Confirmamos tu depósito de {$amount} en {$deposit->toAccount->name}."]
            : ['Depósito no confirmado', "No confirmamos tu depósito de {$amount}: {$deposit->rejection_reason}"];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage($this->title, $this->body, ['type' => 'cash_deposit_reviewed', 'route' => '/mi-caja']);
    }
}
