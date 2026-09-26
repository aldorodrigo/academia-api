<?php

namespace App\Filament\Platform\Resources\Organizations\Tables;

use App\Actions\Invitations\CreateInvitation;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class OrganizationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'memberships as active_members_count' => fn (Builder $q) => $q->where('status', 'active'),
                'invitations as pending_invitations_count' => fn (Builder $q) => $q
                    ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now()),
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Organization $record) => $record->slug),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (OrganizationType $state) => $state->label()),
                TextColumn::make('active_members_count')
                    ->label('Miembros')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('pending_invitations_count')
                    ->label('Invit. pendientes')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Estado')
                    ->state(fn (Organization $record) => $record->isSuspended() ? 'Suspendida' : 'Activa')
                    ->color(fn (Organization $record) => $record->isSuspended() ? 'danger' : 'success')
                    ->tooltip(fn (Organization $record) => $record->suspension_reason)
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Alta')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('suspended')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Suspendidas')
                    ->falseLabel('Activas')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('suspended_at'),
                        false: fn (Builder $q) => $q->whereNull('suspended_at'),
                    ),
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(collect(OrganizationType::cases())->mapWithKeys(fn (OrganizationType $type) => [$type->value => $type->label()])),
            ])
            ->recordActions([
                Action::make('enter')
                    ->label('Entrar al panel')
                    ->iconButton()
                    ->tooltip('Entrar al panel de la organización')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Organization $record) => url("/admin/{$record->slug}"))
                    ->openUrlInNewTab(),
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('inviteAdmin')
                        ->label('Invitar administrador')
                        ->icon(Heroicon::OutlinedUserPlus)
                        ->hidden(fn (Organization $record) => $record->isSuspended())
                        ->schema([
                            TextInput::make('email')->label('Correo electrónico')->email()->required(),
                        ])
                        ->action(function (Organization $record, array $data, Component $livewire) {
                            [, $token] = app(CreateInvitation::class)->handle(
                                $record,
                                $data['email'],
                                [['role' => OrganizationRole::Admin->value]],
                                auth()->user(),
                            );
                            $livewire->replaceMountedAction('showLink', ['token' => $token]);
                        }),
                    Action::make('suspend')
                        ->label('Suspender')
                        ->icon(Heroicon::OutlinedPauseCircle)
                        ->color('danger')
                        ->hidden(fn (Organization $record) => $record->isSuspended())
                        ->modalDescription('Nadie de la organización podrá entrar al panel ni a la app hasta reactivarla. Los datos se conservan.')
                        ->schema([
                            TextInput::make('reason')->label('Motivo')->required()->maxLength(255),
                        ])
                        ->action(function (Organization $record, array $data) {
                            $record->suspend($data['reason']);
                            Notification::make()->title('Organización suspendida')->success()->send();
                        }),
                    Action::make('reactivate')
                        ->label('Reactivar')
                        ->icon(Heroicon::OutlinedPlayCircle)
                        ->color('success')
                        ->visible(fn (Organization $record) => $record->isSuspended())
                        ->requiresConfirmation()
                        ->action(function (Organization $record) {
                            $record->reactivate();
                            Notification::make()->title('Organización reactivada')->success()->send();
                        }),
                ]),
            ]);
    }
}
