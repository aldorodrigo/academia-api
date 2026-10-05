<?php

namespace App\Filament\Resources\Members;

use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\Tables\MembersTable;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Membership;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Personas de la organización (membresías) y sus roles.
 */
class MemberResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Membership::class;

    protected static ?string $slug = 'miembros';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Personas';

    protected static ?int $navigationSort = -1;

    protected static ?string $modelLabel = 'miembro';

    protected static ?string $pluralModelLabel = 'miembros';

    public static function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
        ];
    }
}
