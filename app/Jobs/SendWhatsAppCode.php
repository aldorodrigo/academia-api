<?php

namespace App\Jobs;

use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Manda un código de verificación por WhatsApp (en cola, como el correo).
 */
class SendWhatsAppCode implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(public string $phone, public string $code) {}

    public function handle(WhatsAppSender $sender): void
    {
        $sender->sendCode($this->phone, $this->code);
    }
}
