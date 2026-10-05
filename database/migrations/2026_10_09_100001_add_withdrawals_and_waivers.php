<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            // Baja: motivo y quién la dio (la fecha es `ended_on`).
            $table->string('withdrawal_reason')->nullable()->after('ended_on');
            $table->foreignId('withdrawn_by')->nullable()->after('withdrawal_reason')->constrained('users')->nullOnDelete();
            // Aviso del técnico "dejó de venir": la baja la decide quien puede editar inscripciones.
            $table->timestamp('dropout_reported_at')->nullable()->after('withdrawn_by');
            $table->foreignId('dropout_reported_by')->nullable()->after('dropout_reported_at')->constrained('users')->nullOnDelete();
            $table->string('dropout_note')->nullable()->after('dropout_reported_by');
        });

        Schema::table('charges', function (Blueprint $table) {
            // Condonación: anulación de lo que faltaba pagar (quién, cuándo y por qué en voided_*).
            $table->unsignedBigInteger('waived_amount')->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('waived_amount');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropConstrainedForeignId('dropout_reported_by');
            $table->dropColumn(['withdrawal_reason', 'dropout_reported_at', 'dropout_note']);
        });
    }
};
