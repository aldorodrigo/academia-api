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
        $cloudMessage = CloudMessage::new()
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
