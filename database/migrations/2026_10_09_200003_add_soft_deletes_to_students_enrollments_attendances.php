<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alumnos, inscripciones y asistencias no se borran: se archivan (soft delete) y dejan de aparecer en listas,
     * cuentas e informes, pero queda el historial (ej. una inscripción de la app rechazada).
     */
    public function up(): void
    {
        foreach (['students', 'enrollments', 'attendances'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->softDeletes());
        }

        // Una inscripción archivada no impide volver a inscribirlo en la misma categoría y temporada: la unicidad
        // cuenta solo las vigentes (las archivadas tienen NULL en la columna y no chocan entre sí).
        Schema::table('enrollments', function (Blueprint $table) {
            $table->boolean('not_deleted')->nullable()->storedAs('IF(deleted_at IS NULL, 1, NULL)');
            $table->unique(['student_id', 'group_id', 'season_id', 'not_deleted'], 'enrollments_student_group_season_alive_unique');
        });
        Schema::table('enrollments', fn (Blueprint $table) => $table->dropUnique(['student_id', 'group_id', 'season_id']));
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->unique(['student_id', 'group_id', 'season_id']);
        });
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique('enrollments_student_group_season_alive_unique');
            $table->dropColumn('not_deleted');
        });

        foreach (['students', 'enrollments', 'attendances'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropSoftDeletes());
        }
    }
};
