<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            // 100 = beca total (no se genera la cuota).
            $table->unsignedTinyInteger('percent');
            $table->text('reason');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status')->default('pendiente');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['enrollment_id', 'status']);
        });

        Schema::create('discount_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            // Porcentaje o monto fijo (uno de los dos).
            $table->unsignedTinyInteger('percent')->nullable();
            $table->unsignedBigInteger('fixed_amount')->nullable();
            // Hermanos: 2 = 2º hijo, 3 = 3º en adelante.
            $table->unsignedTinyInteger('sibling_position')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });

        Schema::create('discount_rule_fee_concept', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_concept_id')->constrained()->cascadeOnDelete();

            $table->unique(['discount_rule_id', 'fee_concept_id']);
        });

        Schema::create('discount_rule_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            $table->unique(['discount_rule_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_rule_student');
        Schema::dropIfExists('discount_rule_fee_concept');
        Schema::dropIfExists('discount_rules');
        Schema::dropIfExists('scholarships');
    }
};
