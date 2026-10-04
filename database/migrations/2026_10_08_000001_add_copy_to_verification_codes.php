<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Copia por correo del código que va por WhatsApp: tiene su propio código, que verifica el correo.
     */
    public function up(): void
    {
        Schema::table('verification_codes', function (Blueprint $table) {
            $table->string('copy_destination')->nullable()->after('code_hash');
            $table->string('copy_code_hash')->nullable()->after('copy_destination');
        });
    }

    public function down(): void
    {
        Schema::table('verification_codes', function (Blueprint $table) {
            $table->dropColumn(['copy_destination', 'copy_code_hash']);
        });
    }
};
