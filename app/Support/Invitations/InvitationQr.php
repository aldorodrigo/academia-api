<?php

namespace App\Support\Invitations;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * QR del link de invitación (PNG, sirve para el panel y para el email).
 */
class InvitationQr
{
    public static function png(string $url): string
    {
        return (new Builder(
            writer: new PngWriter,
            data: $url,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 320,
            margin: 12,
        ))->build()->getString();
    }

    public static function dataUri(string $url): string
    {
        return 'data:image/png;base64,'.base64_encode(self::png($url));
    }
}
