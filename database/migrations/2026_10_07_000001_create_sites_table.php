<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lugares con varias canchas: el lugar (Polideportivo, con su dirección) agrupa las canchas, salas o
 * aulas donde se dan las clases (lo que ya era `venues`). Horarios y clases siguen apuntando a la cancha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::table('venues', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('organization_id')->constrained()->cascadeOnDelete();
        });

        // Cada lugar que ya existía pasa a ser un lugar con una cancha del mismo nombre.
        DB::table('venues')->orderBy('id')->each(function (object $venue): void {
            $siteId = DB::table('sites')->insertGetId([
                'organization_id' => $venue->organization_id,
                'name' => $venue->name,
                'address' => $venue->address,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('venues')->where('id', $venue->id)->update(['site_id' => $siteId]);
        });

        // "Cancha 1" se puede repetir en lugares distintos. La clave foránea de la organización usaba
        // el único viejo como índice: primero tiene uno propio.
        Schema::table('venues', function (Blueprint $table) {
            $table->index('organization_id');
            $table->unique(['site_id', 'name']);
        });
        Schema::table('venues', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->unique(['organization_id', 'name']);
        });
        // La clave foránea del lugar usa el único nuevo como índice: primero se suelta.
        Schema::table('venues', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropUnique(['site_id', 'name']);
            $table->dropIndex(['organization_id']);
            $table->dropColumn('site_id');
        });

        Schema::dropIfExists('sites');
    }
};
