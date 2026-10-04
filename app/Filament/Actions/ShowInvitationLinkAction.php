<?php

namespace App\Filament\Actions;

use App\Models\Invitation;
use App\Support\Invitations\InvitationQr;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Facades\Filament;

/**
 * Modal "Invitación lista": muestra el link y el QR una sola vez (el token
 * no se guarda en claro). Se abre con replaceMountedAction('showLink', ['token' => …, 'name' => …, 'phone' => …, 'email' => …]).
 * Con `email`, ya se le mandó por correo (y con `phone` también se le puede mandar por WhatsApp).
 * Con `phone`, el botón de WhatsApp abre el chat con ese número.
 */
class ShowInvitationLinkAction
{
    public static function make(): Action
    {
        return Action::make('showLink')
            ->modalHeading('Invitación lista')
            ->modalDescription(fn (array $arguments) => match (true) {
                filled($arguments['email'] ?? null) && filled($arguments['phone'] ?? null) => "Ya se envió por correo a {$arguments['email']}. También podés mandársela por WhatsApp al ".Phone::display($arguments['phone']).' o mostrarle el QR. Guardala ahora: no se vuelve a mostrar.',
                filled($arguments['phone'] ?? null) => 'Mandásela por WhatsApp al '.Phone::display($arguments['phone']).' o mostrale el QR. Guardala ahora: no se vuelve a mostrar.',
                default => 'Ya se envió por correo. También podés compartir el link o mostrar el QR. Guardalo ahora: no se vuelve a mostrar.',
            })
            ->modalContent(function (array $arguments) {
                $url = Invitation::urlFor($arguments['token']);
                $organization = Filament::getTenant()?->name;
                $greeting = filled($arguments['name'] ?? null) ? 'Hola '.strtok($arguments['name'], ' ').', te' : 'Te';

                return view('filament.invitations.link', [
                    'url' => $url,
                    'qr' => InvitationQr::dataUri($url),
                    // Texto armado para mandar por WhatsApp: al celular invitado o, sin número, se elige a quién.
                    'whatsapp' => 'https://wa.me/'.(filled($arguments['phone'] ?? null) ? Phone::digits($arguments['phone']) : '').'?text='.rawurlencode(
                        "{$greeting} invito a sumarte".($organization ? " a {$organization}" : '').". Creá tu cuenta desde este link: {$url}"
                    ),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Listo');
    }
}
