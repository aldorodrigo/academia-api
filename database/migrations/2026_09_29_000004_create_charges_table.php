<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Un alumno con cargos no se borra.
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fee_concept_id')->constrained()->restrictOnDelete();
            $table->foreignId('tariff_id')->nullable()->constrained()->nullOnDelete();
            // Primer día del mes en los cargos mensuales.
            $table->date('period')->nullable();
            $table->string('description');
            $table->unsignedBigInteger('base_amount');
            $table->unsignedBigInteger('final_amount');
            $table->date('issued_on');
            $table->date('due_on');
            // Cargos automáticos: "enr:{id}:con:{id}:per:{Y-m|once}". Nunca se cobra dos veces lo mismo.
            $table->string('unique_key')->nullable()->unique();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'student_id', 'due_on']);
            $table->index(['organization_id', 'period']);
        });

        Schema::create('charge_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('label');
            // Con signo: negativo para descuentos y becas, positivo para recargos.
            $table->bigInteger('amount');
            $table->foreignId('discount_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scholarship_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_adjustments');
        Schema::dropIfExists('charges');
    }
};
