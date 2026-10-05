<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Cobra directo a la Caja" (docs/PLAN_COBRO_EFECTIVO.md §9): por persona, en su membresía. Por defecto solo
 * quien creó la organización (`organizations.owner_id`). En las que ya existían, el dueño es el de la
 * membresía más vieja con el rol de administrador (si no hay, la membresía más vieja).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('slug')->constrained('users')->nullOnDelete();
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->boolean('collects_to_org_cash')->default(false)->after('status');
            $table->foreignId('collects_to_org_cash_changed_by')->nullable()->after('collects_to_org_cash')->constrained('users')->nullOnDelete();
            $table->timestamp('collects_to_org_cash_changed_at')->nullable()->after('collects_to_org_cash_changed_by');
        });

        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            $admins = DB::table('role_assignments')
                ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
                ->where('role_assignments.organization_id', $organizationId)
                ->where('roles.name', 'admin')
                ->pluck('role_assignments.user_id');

            $membership = DB::table('memberships')->where('organization_id', $organizationId)
                ->whereIn('user_id', $admins)->orderBy('id')->first()
                ?? DB::table('memberships')->where('organization_id', $organizationId)->orderBy('id')->first();

            if ($membership === null) {
                continue;
            }

            DB::table('organizations')->where('id', $organizationId)->update(['owner_id' => $membership->user_id]);
            DB::table('memberships')->where('id', $membership->id)->update(['collects_to_org_cash' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collects_to_org_cash_changed_by');
            $table->dropColumn(['collects_to_org_cash', 'collects_to_org_cash_changed_at']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
