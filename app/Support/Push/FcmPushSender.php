<?php

namespace App\Support\Push;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * Push por Firebase Cloud Messaging, de a 500 dispositivos.
 */
class FcmPushSender implements PushSender
{
    public function __construct(private Messaging $messaging) {}

    public function send(array $tokens, PushMessage $message): array
    {
        $cloudMessage = $message->withActions
            // Android: solo datos (la app la dibuja con botones); iOS: alerta con categoría.
            ? CloudMessage::new()
                ->withData([...$message->data, 'title' => $message->title, 'body' => $message->body])
                ->withAndroidConfig(['priority' => 'high'])
                ->withApnsConfig(['payload' => ['aps' => [
                    'alert' => ['title' => $message->title, 'body' => $message->body],
                    'category' => $message->category,
                    'sound' => 'default',
                ]]])
            : CloudMessage::new()
                ->withNotification(['title' => $message->title, 'body' => $message->body])
                ->withData($message->data)
                ->withDefaultSounds();

        $invalid = [];

        foreach (array_chunk($tokens, 500) as $chunk) {
            $report = $this->messaging->sendMulticast($cloudMessage, $chunk);
            $invalid = [...$invalid, ...$report->invalidTokens(), ...$report->unknownTokens()];
        }

        return $invalid;
    }
}
