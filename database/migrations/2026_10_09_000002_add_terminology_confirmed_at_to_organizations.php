<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El usuario ya decidió cómo les dicen (aceptó o rechazó las palabras de deporte, o las cambió):
 * no se le vuelve a proponer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('terminology_confirmed_at')->nullable()->after('terminology');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('terminology_confirmed_at');
        });
    }
};
