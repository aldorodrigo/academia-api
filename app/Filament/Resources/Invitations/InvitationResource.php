<?php

namespace App\Filament\Resources\Invitations;

use App\Filament\Resources\Invitations\Pages\ListInvitations;
use App\Filament\Resources\Invitations\Tables\InvitationsTable;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Invitation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class InvitationResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Invitation::class;

    protected static ?string $slug = 'invitaciones';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Personas';

    protected static ?string $modelLabel = 'invitación';

    protected static ?string $pluralModelLabel = 'invitaciones';

    protected static ?string $recordTitleAttribute = 'email';

    public static function table(Table $table): Table
    {
        return InvitationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvitations::route('/'),
        ];
    }
}
