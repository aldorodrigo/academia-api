<?php

namespace App\Filament\Resources\Invitations\Pages;

use App\Actions\Invitations\CreateInvitation;
use App\Enums\Gender;
use App\Filament\Actions\ShowInvitationLinkAction;
use App\Filament\Resources\Invitations\InvitationResource;
use App\Filament\Support\ContactField;
use App\Filament\Support\GenderField;
use App\Filament\Support\RoleFields;
use App\Models\Invitation;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
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
                ->modalDescription('Con el celular, le mandás el link por WhatsApp; con el correo, le llega por email. El link vence en '.Invitation::VALID_DAYS.' días y sirve una sola vez.')
                ->modalSubmitActionLabel('Crear invitación')
                ->schema([
                    ContactField::make(),
                    GenderField::make(),
                    Repeater::make('roles')
                        ->label('Roles')
                        ->schema(RoleFields::make())
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Agregar otro rol')
                        ->columns(3),
                ])
                ->action(function (array $data) {
                    $contact = ContactField::split($data['contact']);

                    [$invitation, $token] = app(CreateInvitation::class)->handle(
                        Filament::getTenant(),
                        $contact['email'],
                        array_values($data['roles']),
                        auth()->user(),
                        phone: $contact['phone'],
                        gender: Gender::parse($data['gender'] ?? null),
                    );

                    $this->replaceMountedAction('showLink', ['token' => $token, 'phone' => $invitation->phone]);
                }),
        ];
    }

    public function showLinkAction(): Action
    {
        return ShowInvitationLinkAction::make();
    }
}
