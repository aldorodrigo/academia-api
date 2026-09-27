<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('caja');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // Libro mayor: inmutable. Saldo de una cuenta = suma de sus movimientos.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();
            $table->date('occurred_on');
            $table->bigInteger('amount');
            $table->string('description');
            $table->nullableMorphs('source');
            // Contra-movimiento: apunta al movimiento que anula.
            $table->foreignId('reverses_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['money_account_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('money_accounts');
    }
};
