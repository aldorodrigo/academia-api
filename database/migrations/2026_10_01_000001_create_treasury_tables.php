<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('money_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->unsignedTinyInteger('day_of_month');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('money_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->date('due_on')->nullable();
            $table->date('paid_on')->nullable();
            // pendiente (generado por un recurrente) | pagado | anulado
            $table->string('status')->default('pendiente');
            // Comprobante: ruta en el disco privado.
            $table->string('attachment')->nullable();
            $table->foreignId('recurring_expense_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un gasto por recurrente y mes.
            $table->unique(['recurring_expense_id', 'period']);
            $table->index(['organization_id', 'status', 'paid_on']);
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('transferred_on');
            $table->string('description')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('recurring_expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('suppliers');
    }
};
