<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transferencia que la familia le mandó por WhatsApp a un técnico, tesorero o admin y que este registra
        // desde la app: `user_id` es quien la registró y `guardian_id` el tutor que la mandó (si se sabe).
        Schema::table('payment_reports', function (Blueprint $table) {
            $table->boolean('registered_by_staff')->default(false)->after('user_id');
            $table->foreignId('guardian_id')->nullable()->after('registered_by_staff')->constrained()->nullOnDelete();
            // Retirar un comprobante en revisión no lo borra (regla del proyecto: siempre soft delete).
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('payment_reports', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropConstrainedForeignId('guardian_id');
            $table->dropColumn('registered_by_staff');
        });
    }
};
