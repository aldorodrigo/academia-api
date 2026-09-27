<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();
            $table->date('received_on');
            $table->unsignedBigInteger('amount');
            $table->string('method');
            $table->string('reference')->nullable();
            // Correlativo interno por organización, sin huecos.
            $table->unsignedInteger('receipt_number');
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'receipt_number']);
            $table->index(['family_id', 'received_on']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            // Pronto pago aplicado al saldar el cargo a tiempo.
            $table->unsignedBigInteger('early_payment_discount')->default(0);
            $table->string('early_payment_label')->nullable();
            $table->timestamps();

            $table->index('charge_id');
        });

        Schema::table('discount_rules', function (Blueprint $table) {
            // Pronto pago: pagando hasta este día del mes del cargo.
            $table->unsignedTinyInteger('until_day')->nullable()->after('sibling_position');
        });
    }

    public function down(): void
    {
        Schema::table('discount_rules', function (Blueprint $table) {
            $table->dropColumn('until_day');
        });
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
