<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nunca se borra de verdad: "Eliminar" un tutor del panel lo archiva y, al deshacer una clase suspendida,
     * los descuentos pendientes (charge_waivers) se archivan en vez de borrarse.
     */
    public function up(): void
    {
        foreach (['guardians', 'charge_waivers'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->softDeletes());
        }
    }

    public function down(): void
    {
        foreach (['guardians', 'charge_waivers'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropSoftDeletes());
        }
    }
};
