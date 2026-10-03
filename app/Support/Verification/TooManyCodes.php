<?php

namespace App\Support\Verification;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * No se puede mandar otro código ahora (límites, tope diario o WhatsApp pausado). No dice cuál.
 */
class TooManyCodes extends RuntimeException
{
    public const WAIT = 'Esperá un momento antes de pedir otro código.';

    public const UNAVAILABLE = 'No pudimos mandar el código. Probá de nuevo más tarde.';

    public static function wait(): self
    {
        return new self(self::WAIT);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 429);
    }
}
