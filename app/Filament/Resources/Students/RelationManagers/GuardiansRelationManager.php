<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Enums\GuardianRelationship;
use App\Filament\Actions\ShowInvitationLinkAction;
use App\Filament\Resources\Guardians\GuardianResource;
use App\Filament\Resources\Guardians\Tables\GuardiansTable;
use App\Filament\Support\Terms;
use App\Models\Family;
use App\Models\Guardian;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class GuardiansRelationManager extends RelationManager
{
    protected static string $relationship = 'guardians';

    protected static ?string $recordTitleAttribute = 'last_name';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return ucfirst(Terms::plural('guardian', 'Tutor'));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            ...GuardianResource::fields(),
            self::relationshipField(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // "Crear tutor", no "Crear guardian" (el nombre del modelo).
            ->modelLabel(fn () => Terms::singular('guardian', 'Tutor'))
            ->pluralModelLabel(fn () => Terms::plural('guardian', 'Tutor'))
            ->recordTitle(fn (Guardian $record) => $record->full_name)
            ->columns([
                TextColumn::make('full_name')->label('Nombre'),
                TextColumn::make('pivot.relationship')->label('Parentesco')
                    ->formatStateUsing(fn (?string $state) => GuardianRelationship::parse($state)->label()),
                TextColumn::make('email')->label('Correo'),
                TextColumn::make('phone')->label('Celular')->formatStateUsing(fn (?string $state) => Phone::display($state) ?? $state),
                IconColumn::make('user_id')->label('Usa la app')->boolean()->state(fn (Guardian $record) => $record->hasAccount()),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Vincular existente')
                    ->recordSelectSearchColumns(['first_name', 'last_name', 'email', 'document'])
                    ->schema(fn (AttachAction $action) => [$action->getRecordSelect(), self::relationshipField()])
                    ->after(fn () => Family::syncFor($this->getOwnerRecord())),
                CreateAction::make()->after(fn () => Family::syncFor($this->getOwnerRecord())),
            ])
            ->recordActions([
                GuardiansTable::inviteAction(),
                EditAction::make(),
                DetachAction::make()->label('Desvincular'),
            ]);
    }

    private static function relationshipField(): Select
    {
        return Select::make('relationship')
            ->label('Parentesco')
            ->options(GuardianRelationship::class)
            ->default(GuardianRelationship::Guardian->value)
            ->required();
    }

    /**
     * Link de la invitación de un tutor que solo tiene celular (para mandarlo por WhatsApp).
     */
    public function showLinkAction(): Action
    {
        return ShowInvitationLinkAction::make();
    }
}
