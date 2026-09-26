<?php

namespace App\Filament\Resources\Invitations\Pages;

use App\Actions\Invitations\CreateInvitation;
use App\Filament\Actions\ShowInvitationLinkAction;
use App\Filament\Resources\Invitations\InvitationResource;
use App\Filament\Support\RoleFields;
use App\Models\Invitation;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListInvitations extends ListRecords
{
    protected static string $resource = InvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invitar')
                ->icon(Heroicon::OutlinedUserPlus)
                ->authorize('create', Invitation::class)
                ->modalHeading('Invitar a una persona')
                ->modalDescription('Le llega un correo con el link y el QR. El link vence en '.Invitation::VALID_DAYS.' días y sirve una sola vez.')
                ->modalSubmitActionLabel('Crear invitación')
                ->schema([
                    TextInput::make('email')
                        ->label('Correo electrónico')
                        ->email()
                        ->required(),
                    Repeater::make('roles')
                        ->label('Roles')
                        ->schema(RoleFields::make())
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Agregar otro rol')
                        ->columns(3),
                ])
                ->action(function (array $data) {
                    [, $token] = app(CreateInvitation::class)->handle(
                        Filament::getTenant(),
                        $data['email'],
                        array_values($data['roles']),
                        auth()->user(),
                    );

                    $this->replaceMountedAction('showLink', ['token' => $token]);
                }),
        ];
    }

    public function showLinkAction(): Action
    {
        return ShowInvitationLinkAction::make();
    }
}
