<?php

namespace App\Support\Push;

use Illuminate\Support\Facades\Log;

/**
 * Sin Firebase configurado (desarrollo): deja el push en el log.
 */
class LogPushSender implements PushSender
{
    public function send(array $tokens, PushMessage $message): array
    {
        Log::info('Push (sin Firebase)', [
            'tokens' => count($tokens),
            'title' => $message->title,
            'body' => $message->body,
            'data' => $message->data,
        ]);

        return [];
    }
}
