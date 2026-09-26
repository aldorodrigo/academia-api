<?php

namespace App\Filament\Resources\Guardians\Tables;

use App\Actions\Invitations\CreateInvitation;
use App\Models\Guardian;
use App\Models\Invitation;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GuardiansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['students', 'family']))
            ->columns([
                TextColumn::make('last_name')->label('Apellido')->searchable()->sortable(),
                TextColumn::make('first_name')->label('Nombre')->searchable(),
                TextColumn::make('email')->label('Correo')->searchable(),
                TextColumn::make('phone')->label('Teléfono'),
                TextColumn::make('students.first_name')->label('Hijos')->badge(),
                TextColumn::make('family.name')->label('Familia')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('has_account')->label('Usa la app')->boolean()
                    ->state(fn (Guardian $record) => $record->hasAccount()),
            ])
            ->filters([
                TernaryFilter::make('has_account')
                    ->label('Usa la app')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('user_id'),
                        false: fn (Builder $query) => $query->whereNull('user_id'),
                    ),
            ])
            ->defaultSort('last_name')
            ->recordActions([self::inviteAction(), EditAction::make(), DeleteAction::make()])
            ->toolbarActions([
                BulkAction::make('invite')
                    ->label('Invitar a la app')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->requiresConfirmation()
                    ->modalDescription('Se envía la invitación a los seleccionados que tienen correo y todavía no usan la app.')
                    ->authorize('create', Invitation::class)
                    ->action(function (Collection $records): void {
                        $sent = $records
                            ->filter(fn (Guardian $guardian) => filled($guardian->email) && ! $guardian->hasAccount())
                            ->each(fn (Guardian $guardian) => app(CreateInvitation::class)->forGuardian($guardian, auth()->user()))
                            ->count();

                        Notification::make()->success()->title("Invitaciones enviadas: {$sent}.")->send();
                    }),
            ]);
    }

    /**
     * Invitación como tutor: al aceptarla ve a sus hijos en la app.
     */
    public static function inviteAction(): Action
    {
        return Action::make('invite')
            ->label(fn (Guardian $record) => $record->invitations()->exists() ? 'Reenviar invitación' : 'Invitar a la app')
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (Guardian $record) => filled($record->email) && ! $record->hasAccount())
            ->authorize('create', Invitation::class)
            ->requiresConfirmation()
            ->modalDescription(fn (Guardian $record) => "Se envía la invitación a {$record->email}.")
            ->action(function (Guardian $record): void {
                app(CreateInvitation::class)->forGuardian($record, auth()->user());

                Notification::make()->success()->title('Invitación enviada.')->send();
            });
    }
}
