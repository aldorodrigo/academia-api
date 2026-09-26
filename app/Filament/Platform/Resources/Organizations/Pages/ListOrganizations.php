<?php

namespace App\Filament\Platform\Resources\Organizations\Pages;

use App\Actions\Platform\CreateOrganization;
use App\Filament\Actions\ShowInvitationLinkAction;
use App\Filament\Platform\Resources\Organizations\OrganizationResource;
use App\Filament\Platform\Resources\Organizations\Schemas\OrganizationForm;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;

class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createOrganization')
                ->label('Nueva organización')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Nueva organización')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalSubmitActionLabel('Crear e invitar')
                ->schema(OrganizationForm::fields(withAdminEmail: true))
                ->action(function (array $data) {
                    [, $token] = app(CreateOrganization::class)->handle(
                        Arr::except($data, 'admin_email'),
                        $data['admin_email'],
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
