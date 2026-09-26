<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            // Invitación enviada a un tutor cargado: al aceptarla se vincula a la cuenta.
            $table->foreignId('guardian_id')->nullable()->after('roles')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guardian_id');
        });
    }
};
