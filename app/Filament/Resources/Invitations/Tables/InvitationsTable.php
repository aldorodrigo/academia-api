<?php

namespace App\Filament\Resources\Invitations\Tables;

use App\Actions\Invitations\CreateInvitation;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Component;

class InvitationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('Correo')
                    ->searchable(),
                TextColumn::make('roles')
                    ->label('Roles')
                    ->state(fn (Invitation $record) => $record->roleLabels())
                    ->badge(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->state(fn (Invitation $record) => $record->status()->label())
                    ->color(fn (Invitation $record) => $record->status()->color())
                    ->badge(),
                TextColumn::make('expires_at')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('invitedBy.name')
                    ->label('Invitó')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('resend')
                    ->label('Reenviar')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (Invitation $record) => in_array($record->status(), [InvitationStatus::Pending, InvitationStatus::Expired], true))
                    ->authorize('create', Invitation::class)
                    ->requiresConfirmation()
                    ->modalDescription('Se genera un link nuevo; el anterior deja de funcionar.')
                    ->action(function (Invitation $record, Component $livewire) {
                        $token = app(CreateInvitation::class)->resend($record);
                        $livewire->replaceMountedAction('showLink', ['token' => $token]);
                    }),
                Action::make('revoke')
                    ->label('Revocar')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Invitation $record) => $record->isPending())
                    ->authorize('delete')
                    ->requiresConfirmation()
                    ->action(function (Invitation $record) {
                        $record->update(['revoked_at' => now()]);
                        Notification::make()->title('Invitación revocada')->success()->send();
                    }),
            ]);
    }
}
