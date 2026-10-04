<?php

namespace App\Filament\Actions;

use App\Models\Invitation;
use App\Support\Invitations\InvitationQr;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Facades\Filament;

/**
 * Modal "Invitación lista": muestra el link y el QR una sola vez (el token
 * no se guarda en claro). Se abre con replaceMountedAction('showLink', ['token' => …, 'name' => …, 'phone' => …, 'email' => …]);
 * el celular y el correo salen de la invitación (los argumentos quedan de respaldo).
 * Con correo, ya se le mandó por correo; con celular, el botón de WhatsApp abre el chat con ese número.
 */
class ShowInvitationLinkAction
{
    public static function make(): Action
    {
        return Action::make('showLink')
            ->modalHeading('Invitación lista')
            ->modalDescription(function (array $arguments) {
                [$phone, $email] = self::contacts($arguments);

                return match (true) {
                    $email !== null && $phone !== null => "Ya se envió por correo a {$email}. También podés mandársela por WhatsApp al ".Phone::display($phone).' o mostrarle el QR. Guardala ahora: no se vuelve a mostrar.',
                    $phone !== null => 'Mandásela por WhatsApp al '.Phone::display($phone).' o mostrale el QR. Guardala ahora: no se vuelve a mostrar.',
                    default => 'Ya se envió por correo. También podés compartir el link o mostrar el QR. Guardalo ahora: no se vuelve a mostrar.',
                };
            })
            ->modalContent(function (array $arguments) {
                $url = Invitation::urlFor($arguments['token']);
                $invitation = Invitation::findByToken($arguments['token']);
                [$phone] = self::contacts($arguments);

                // Texto con la marca para mandar por WhatsApp: al celular invitado o, sin número, se elige a quién.
                $text = $invitation?->whatsappText($arguments['token'])
                    ?? 'Hola, te invito a sumarte'.(Filament::getTenant() ? ' a *'.Filament::getTenant()->name.'*' : '')." en *Tuku*. Creá tu cuenta desde este link:\n{$url}";

                return view('filament.invitations.link', [
                    'url' => $url,
                    'qr' => InvitationQr::dataUri($url),
                    'whatsapp' => Invitation::whatsappLink($phone, $text),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Listo');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: ?string, 1: ?string} celular y correo de la invitación
     */
    private static function contacts(array $arguments): array
    {
        $invitation = Invitation::findByToken($arguments['token']);

        return [
            $invitation?->phone ?? (filled($arguments['phone'] ?? null) ? $arguments['phone'] : null),
            $invitation?->email ?? (filled($arguments['email'] ?? null) ? $arguments['email'] : null),
        ];
    }
}
