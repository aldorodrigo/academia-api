<?php

namespace App\Filament\Resources\Guardians\Pages;

use App\Filament\Actions\ShowInvitationLinkAction;
use App\Filament\Resources\Guardians\GuardianResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGuardians extends ManageRecords
{
    protected static string $resource = GuardianResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /**
     * Link de la invitación de un tutor que solo tiene celular (para mandarlo por WhatsApp).
     */
    public function showLinkAction(): Action
    {
        return ShowInvitationLinkAction::make();
    }
}
