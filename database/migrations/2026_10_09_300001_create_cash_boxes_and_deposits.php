<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Roles base que cobran en efectivo desde la app por defecto (DefaultPermissions). */
    private const COLLECTORS = ['instructor', 'tesorero', 'protesorero'];

    public function up(): void
    {
        // Caja personal: la plata del club que tiene quien cobra en efectivo desde la app.
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->unique(['organization_id', 'user_id']);
        });

        // Depósito (rendición) de una caja personal a una cuenta del club: por confirmar hasta que lo confirman.
        Schema::create('cash_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('deposited_on');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('pendiente');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('transfer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['money_account_id', 'status']);
        });

        // Los roles que ya existen reciben el permiso nuevo (los nuevos nacen con él).
        $now = now();
        DB::table('permissions')->insertOrIgnore(['name' => 'Collect:Payments', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $permission = DB::table('permissions')->where('name', 'Collect:Payments')->where('guard_name', 'web')->value('id');

        DB::table('roles')->whereIn('name', self::COLLECTORS)->where('guard_name', 'web')->pluck('id')
            ->each(fn (int $role) => DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permission, 'role_id' => $role]));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_deposits');
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
        DB::table('permissions')->where('name', 'Collect:Payments')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
