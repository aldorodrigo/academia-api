<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Confirmar inscripciones de la app: el secretario y el prosecretario en todas las categorías y el técnico en
     * las suyas (DefaultPermissions lo da a los roles nuevos; acá, a los que ya existen). El admin los edita en Roles.
     */
    public function up(): void
    {
        $all = Permission::findOrCreate('Manage:EnrollmentRequests', 'web');
        $own = Permission::findOrCreate('Confirm:GroupEnrollments', 'web');

        Role::query()->withoutGlobalScopes()->whereIn('name', ['secretario', 'prosecretario'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($all));
        Role::query()->withoutGlobalScopes()->where('name', 'instructor')->get()
            ->each(fn (Role $role) => $role->givePermissionTo($own));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', ['Manage:EnrollmentRequests', 'Confirm:GroupEnrollments'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
