<?php

namespace App\Filament\Platform\Resources\Users\Tables;

use App\Actions\Platform\SetSuperAdmin;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('activeOrganizations'))
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('email')->label('Correo')->searchable(),
                IconColumn::make('is_super_admin')->label('Super admin')->boolean(),
                TextColumn::make('activeOrganizations.name')->label('Organizaciones')->badge(),
                TextColumn::make('created_at')->label('Alta')->date('d/m/Y')->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                Filter::make('super_admins')
                    ->label('Solo super admins')
                    ->query(fn (Builder $q) => $q->where('is_super_admin', true)),
            ])
            ->recordActions([
                Action::make('grantSuperAdmin')
                    ->label('Hacer super admin')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->hidden(fn (User $record) => $record->is_super_admin)
                    ->requiresConfirmation()
                    ->modalDescription('Tendrá acceso total a todas las organizaciones y a esta plataforma.')
                    ->action(fn (User $record) => self::set($record, true)),
                Action::make('revokeSuperAdmin')
                    ->label('Quitar super admin')
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->color('danger')
                    ->visible(fn (User $record) => $record->is_super_admin)
                    ->requiresConfirmation()
                    ->action(fn (User $record) => self::set($record, false)),
            ]);
    }

    private static function set(User $user, bool $grant): void
    {
        try {
            app(SetSuperAdmin::class)->handle($user, $grant, auth()->user());
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        }

        Notification::make()->title($grant ? 'Ahora es super admin' : 'Ya no es super admin')->success()->send();
    }
}
