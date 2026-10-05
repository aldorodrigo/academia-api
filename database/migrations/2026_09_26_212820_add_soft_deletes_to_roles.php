<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Va justo después de crear las tablas de permisos: las migraciones de datos que siguen usan el modelo Role
     * (con SoftDeletes). En una base que ya las corrió se aplica igual, como cualquier migración pendiente.
     *
     * Un rol creado por la organización que nadie tiene se puede borrar: se archiva (soft delete) y queda el
     * historial de quién lo tuvo (`role_assignments` no se borra en cascada). La unicidad del nombre cuenta solo
     * los vigentes, para poder crear otro con el mismo nombre (los archivados tienen NULL y no chocan).
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->softDeletes();
            $table->boolean('not_deleted')->nullable()->storedAs('IF(deleted_at IS NULL, 1, NULL)');
            $table->unique(['organization_id', 'name', 'guard_name', 'not_deleted'], 'roles_team_name_guard_alive_unique');
        });
        Schema::table('roles', fn (Blueprint $table) => $table->dropUnique(['organization_id', 'name', 'guard_name']));
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unique(['organization_id', 'name', 'guard_name']);
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_team_name_guard_alive_unique');
            $table->dropColumn('not_deleted');
            $table->dropSoftDeletes();
        });
    }
};
