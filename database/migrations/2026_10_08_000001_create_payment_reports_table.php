<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comprobantes de transferencia que informa el tutor: pendientes hasta que se validan.
        Schema::create('payment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('money_account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            // Cuotas que el tutor eligió pagar (vacío = pago a cuenta).
            $table->json('charge_ids');
            $table->string('proof_path');
            $table->string('proof_name');
            $table->string('status')->default('pendiente');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['family_id', 'created_at']);
        });

        Schema::table('money_accounts', function (Blueprint $table) {
            // Datos para transferir que ve el tutor (banco, cuenta, titular, RUC o alias).
            $table->text('transfer_details')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('money_accounts', fn (Blueprint $table) => $table->dropColumn('transfer_details'));
        Schema::dropIfExists('payment_reports');
    }
};
