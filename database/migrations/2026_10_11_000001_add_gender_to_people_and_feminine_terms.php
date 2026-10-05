<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Género opcional de las personas (female / male; null = sin especificar) para nombrarlas bien
     * ("Técnica", "Jugadora"), y las formas femeninas que ajusta la organización si la regla no alcanza
     * ({"student": "Jugadora"}). Ver docs/PLAN_GENERO.md. A los tutores no se les pregunta: sale del parentesco.
     */
    public function up(): void
    {
        foreach (['students', 'users', 'invitations', 'enrollment_requests'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('gender', 10)->nullable());
        }

        Schema::table('organizations', fn (Blueprint $table) => $table->json('terminology_feminine')->nullable()->after('terminology'));
    }

    public function down(): void
    {
        foreach (['students', 'users', 'invitations', 'enrollment_requests'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('gender'));
        }

        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('terminology_feminine'));
    }
};
