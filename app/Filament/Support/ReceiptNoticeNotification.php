<?php

namespace App\Filament\Support;

use App\Actions\Billing\ReceiptNotice;
use App\Http\Controllers\ReceiptController;
use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Después de registrar un pago en el panel: la verdad sobre a quién le llega el recibo ("A Laura Benítez le llega en
 * la app. Carlos Ortiz no tiene la app: no le llega.") y, para quien no lo recibe y tiene celular, "Mandar recibo por
 * WhatsApp a …" con el mensaje y el link al recibo (30 días).
 */
class ReceiptNoticeNotification
{
    public static function make(Payment $payment, string $title, ?string $extra = null): Notification
    {
        $notice = ReceiptNotice::toArray($payment);
        $whatsapp = collect($notice['reach'])->filter(fn (array $person) => $person['whatsapp_url'] !== null)->values();

        return Notification::make()
            ->success()
            ->title($title)
            ->body(collect([$extra, ReceiptNotice::describe($payment)])->filter()->join(' '))
            ->actions([
                Action::make('receipt')->label('Descargar recibo')->button()
                    ->url(ReceiptController::signedUrl($payment), shouldOpenInNewTab: true),
                ...$whatsapp->map(fn (array $person, int $i) => Action::make("whatsapp{$i}")
                    ->label("Mandar recibo por WhatsApp a {$person['name']}")
                    ->color('success')
                    ->link()
                    ->url($person['whatsapp_url'], shouldOpenInNewTab: true))->all(),
            ])
            ->persistent();
    }
}
