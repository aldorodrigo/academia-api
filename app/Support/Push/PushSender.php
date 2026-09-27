<?php

namespace App\Support\Push;

interface PushSender
{
    /**
     * Envía el mensaje a los dispositivos y devuelve los tokens que ya no sirven.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    public function send(array $tokens, PushMessage $message): array;
}
