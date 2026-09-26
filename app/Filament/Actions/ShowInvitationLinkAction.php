<?php

namespace App\Filament\Actions;

use App\Models\Invitation;
use App\Support\Invitations\InvitationQr;
use Filament\Actions\Action;

/**
 * Modal "Invitación lista": muestra el link y el QR una sola vez (el token
 * no se guarda en claro). Se abre con replaceMountedAction('showLink', ['token' => …]).
 */
class ShowInvitationLinkAction
{
    public static function make(): Action
    {
        return Action::make('showLink')
            ->modalHeading('Invitación lista')
            ->modalDescription('Ya se envió por correo. También podés compartir el link o mostrar el QR. Guardalo ahora: no se vuelve a mostrar.')
            ->modalContent(function (array $arguments) {
                $url = Invitation::urlFor($arguments['token']);

                return view('filament.invitations.link', [
                    'url' => $url,
                    'qr' => InvitationQr::dataUri($url),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Listo');
    }
}
