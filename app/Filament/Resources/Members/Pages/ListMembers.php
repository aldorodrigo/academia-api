<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Invitations\InvitationResource;
use App\Filament\Resources\Members\MemberResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invitations')
                ->label('Invitar')
                ->icon(Heroicon::OutlinedUserPlus)
                ->url(fn () => InvitationResource::getUrl()),
        ];
    }
}
