<?php

namespace App\Filament\Resources\Members\Tables;

use App\Actions\Billing\CashCollectionAccess;
use App\Actions\Billing\SetCollectsToOrgCash;
use App\Enums\MembershipStatus;
use App\Filament\Support\RoleFields;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Support\Roles\RoleAssigner;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.email')
                    ->label('Correo')
                    ->searchable(),
                TextColumn::make('roles')
                    ->label('Perfiles')
                    ->state(fn (Membership $record) => self::profiles($record))
                    ->badge(),
                // Solo para quienes cobran en efectivo: si lo cobrado entra directo a la Caja o lo rinden.
                TextColumn::make('collects_to_org_cash')
                    ->label('Efectivo')
                    ->state(fn (Membership $record) => self::canCollect($record)
                        ? ($record->collects_to_org_cash ? 'Cobra directo a la Caja' : 'Rinde lo que cobra')
                        : null)
                    ->description(fn (Membership $record) => $record->user_id === self::organization()->owner_id ? 'Creó la organización' : null)
                    ->color(fn (Membership $record) => $record->collects_to_org_cash ? 'success' : 'gray')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (MembershipStatus $state) => $state === MembershipStatus::Active ? 'Activo' : 'Inactivo')
                    ->color(fn (MembershipStatus $state) => $state === MembershipStatus::Active ? 'success' : 'gray')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(['active' => 'Activo', 'inactive' => 'Inactivo']),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('assignRole')
                        ->label('Asignar rol')
                        ->icon(Heroicon::OutlinedPlusCircle)
                        ->authorize('update')
                        ->schema(RoleFields::make())
                        ->action(function (Membership $record, array $data) {
                            app(RoleAssigner::class)->assign(
                                self::organization(),
                                $record->user,
                                self::role($data['role']),
                                filled($data['starts_on'] ?? null) ? Carbon::parse($data['starts_on']) : null,
                                filled($data['ends_on'] ?? null) ? Carbon::parse($data['ends_on']) : null,
                                auth()->user(),
                            );
                            Notification::make()->title('Rol asignado')->success()->send();
                        }),
                    Action::make('endRole')
                        ->label('Quitar rol')
                        ->icon(Heroicon::OutlinedMinusCircle)
                        ->color('danger')
                        ->authorize('update')
                        ->visible(fn (Membership $record) => self::activeAssignments($record)->isNotEmpty())
                        ->schema(fn (Membership $record) => [
                            Select::make('assignment')
                                ->label('Rol')
                                ->options(self::activeAssignments($record)->mapWithKeys(
                                    fn (RoleAssignment $assignment) => [$assignment->id => $assignment->description()],
                                ))
                                ->required(),
                        ])
                        ->action(function (Membership $record, array $data) {
                            $assignment = self::activeAssignments($record)->firstWhere('id', (int) $data['assignment']);
                            app(RoleAssigner::class)->end($assignment);
                            Notification::make()->title('Rol quitado')->success()->send();
                        }),
                    Action::make('history')
                        ->label('Historial de roles')
                        ->icon(Heroicon::OutlinedClock)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Cerrar')
                        ->modalContent(fn (Membership $record) => view('filament.members.history', [
                            'assignments' => RoleAssignment::query()
                                ->with(['role', 'organization', 'assignedBy'])
                                ->where('user_id', $record->user_id)
                                ->latest('id')
                                ->get(),
                        ])),
                    Action::make('collectsToOrgCash')
                        ->label(fn (Membership $record) => $record->collects_to_org_cash ? 'Que rinda lo que cobra' : 'Que cobre directo a la Caja')
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->authorize('update')
                        ->visible(fn (Membership $record) => self::canCollect($record))
                        ->requiresConfirmation()
                        ->modalHeading(fn (Membership $record) => $record->collects_to_org_cash
                            ? "{$record->user->name} rinde lo que cobra"
                            : "{$record->user->name} cobra directo a la Caja")
                        ->modalDescription(fn (Membership $record) => $record->collects_to_org_cash
                            ? 'Lo que cobre en efectivo queda en su caja hasta que lo deposite y alguien confirme el depósito.'
                            : 'Lo que cobre en efectivo entra directo en la Caja del club (o en la cuenta del club que elija), sin caja propia ni depósito. Lo que ya tiene en su caja sigue ahí hasta que lo deposite.')
                        ->action(function (Membership $record): void {
                            app(SetCollectsToOrgCash::class)->handle($record, ! $record->collects_to_org_cash, auth()->user());
                            Notification::make()->success()->title($record->collects_to_org_cash ? 'Cobra directo a la Caja.' : 'Rinde lo que cobra.')->send();
                        }),
                    Action::make('toggleStatus')
                        ->label(fn (Membership $record) => $record->status === MembershipStatus::Active ? 'Desactivar' : 'Activar')
                        ->icon(Heroicon::OutlinedPower)
                        ->authorize('update')
                        ->hidden(fn (Membership $record) => $record->user_id === auth()->id())
                        ->requiresConfirmation()
                        ->modalDescription('Un miembro inactivo no puede entrar a la organización (ni en la app ni en el panel).')
                        ->action(fn (Membership $record) => $record->update([
                            'status' => $record->status === MembershipStatus::Active ? MembershipStatus::Inactive : MembershipStatus::Active,
                        ])),
                ]),
            ]);
    }

    private static function canCollect(Membership $record): bool
    {
        return $record->status === MembershipStatus::Active && $record->user !== null && CashCollectionAccess::canCollect($record->user);
    }

    /**
     * @return list<string>
     */
    private static function profiles(Membership $record): array
    {
        $profiles = self::activeAssignments($record)->map->description()->all();

        return $record->user->is_super_admin ? ['Super admin', ...$profiles] : $profiles;
    }

    /**
     * @return Collection<int, RoleAssignment>
     */
    private static function activeAssignments(Membership $record)
    {
        return $record->user->currentRoleAssignments(self::organization());
    }

    private static function organization(): Organization
    {
        return Filament::getTenant();
    }

    private static function role(string $name): Role
    {
        return Role::query()
            ->where('organization_id', self::organization()->id)
            ->where('name', $name)
            ->firstOrFail();
    }
}
