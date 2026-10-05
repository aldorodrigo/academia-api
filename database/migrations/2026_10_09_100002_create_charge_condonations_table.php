<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Cada condonación y, si se deshizo, quién, cuándo y por qué (el cargo guarda solo la vigente).
        Schema::create('charge_condonations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('undo_reason')->nullable();
            $table->timestamps();

            $table->index(['charge_id', 'undone_at']);
        });

        // El aviso de baja lo puede dar el técnico ("dejó de venir") o el tutor ("deja el club").
        Schema::table('enrollments', function (Blueprint $table) {
            $table->string('dropout_source', 20)->nullable()->after('dropout_note');
        });

        // "Condonar deudas" nace con el tesorero y el presidente (DefaultPermissions); a los roles
        // que ya existen se les agrega acá. El admin lo puede quitar en Roles.
        $roleIds = DB::table('roles')->whereIn('name', ['tesorero', 'presidente'])->pluck('id');

        if ($roleIds->isNotEmpty()) {
            $permission = Permission::findOrCreate('Waive:Charge', 'web');

            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permission->id, 'role_id' => $roleId]);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('dropout_source');
        });

        Schema::dropIfExists('charge_condonations');
    }
};
