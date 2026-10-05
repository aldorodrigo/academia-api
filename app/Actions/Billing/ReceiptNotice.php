<?php

namespace App\Actions\Billing;

use App\Actions\Enrollments\WithdrawEnrollment;
use App\Http\Controllers\ReceiptController;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\Student;
use App\Support\Money;
use App\Support\Phone;

/**
 * A quién le llega el aviso del recibo y por dónde, de verdad (como en la baja, `WithdrawEnrollment::noticeReach`):
 * cada tutor de la familia y cada alumno adulto con cuenta. Con cuenta le queda en "Avisos" de la app (`app`), más
 * `push` si tiene la app instalada y `mail` si tiene correo para copias. Sin cuenta no le llega nada: si tiene
 * celular, `whatsapp_url` abre WhatsApp con el mensaje y el link al recibo (vale 30 días) para mandárselo a mano.
 */
class ReceiptNotice
{
    /**
     * @return list<array{name: string, user_id: ?int, channels: list<string>, phone: ?string, whatsapp_phone: ?string}>
     */
    public static function reach(Payment $payment): array
    {
        $guardians = Guardian::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('family_id', $payment->family_id)->with('user.deviceTokens')->orderBy('id')->get();
        $students = Student::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('family_id', $payment->family_id)->whereNotNull('user_id')->with('user.deviceTokens')->orderBy('id')->get();

        $seen = [];
        $reach = [];

        foreach ($guardians as $guardian) {
            $user = $guardian->user;
            if ($user !== null && isset($seen[$user->id])) {
                continue;
            }
            if ($user !== null) {
                $seen[$user->id] = true;
            }

            $mobile = $user === null ? Phone::mobile($guardian->phone) : null;
            $reach[] = [
                'name' => $guardian->full_name ?: (string) $user?->name,
                'user_id' => $user?->id,
                'channels' => $user === null ? [] : WithdrawEnrollment::channelsOf($user),
                'phone' => $user === null ? Phone::display($guardian->phone) : null,
                'whatsapp_phone' => $mobile === null ? null : Phone::digits($mobile),
            ];
        }

        foreach ($students as $student) {
            if ($student->user === null || isset($seen[$student->user->id])) {
                continue;
            }
            $seen[$student->user->id] = true;
            $reach[] = [
                'name' => $student->user->name,
                'user_id' => $student->user->id,
                'channels' => WithdrawEnrollment::channelsOf($student->user),
                'phone' => null,
                'whatsapp_phone' => null,
            ];
        }

        return $reach;
    }

    /**
     * Mensaje para WhatsApp con el link al recibo (30 días).
     */
    public static function message(Payment $payment, ?string $url = null): string
    {
        $payment->loadMissing('organization');
        $url ??= ReceiptController::shareUrl($payment);

        return "Hola, te mandamos el recibo N° {$payment->receiptLabel()} del pago de ".Money::pyg($payment->amount)->format()
            ." a {$payment->organization->name}: {$url} (el link vale ".ReceiptController::SHARE_DAYS.' días). ¡Muchas gracias!';
    }

    /**
     * Lo que devuelve la API después de cobrar: alcance por persona (con `description` y, si no le llega,
     * `whatsapp_url`), el mensaje y el link largo al recibo.
     *
     * @return array{reach: list<array<string, mixed>>, message: string, receipt_url: string}
     */
    public static function toArray(Payment $payment): array
    {
        $url = ReceiptController::shareUrl($payment);
        $message = self::message($payment, $url);

        return [
            'reach' => array_map(fn (array $person) => [
                'name' => $person['name'],
                'channels' => $person['channels'],
                'phone' => $person['phone'],
                'whatsapp_phone' => $person['whatsapp_phone'],
                'description' => WithdrawEnrollment::describeReach($person),
                'whatsapp_url' => $person['channels'] === [] && $person['whatsapp_phone'] !== null
                    ? WithdrawEnrollment::whatsappUrl($person['whatsapp_phone'], $message)
                    : null,
            ], self::reach($payment)),
            'message' => $message,
            'receipt_url' => $url,
        ];
    }

    /**
     * Para el panel: "A Laura Benítez le llega en la app. Carlos Ortiz no tiene la app: no le llega. …".
     */
    public static function describe(Payment $payment): string
    {
        $reach = self::reach($payment);

        if ($reach === []) {
            return 'La familia no tiene a nadie cargado para avisarle: mandale el recibo por otro medio.';
        }

        return collect($reach)->map(fn (array $person) => WithdrawEnrollment::describeReach($person))->join(' ');
    }
}
