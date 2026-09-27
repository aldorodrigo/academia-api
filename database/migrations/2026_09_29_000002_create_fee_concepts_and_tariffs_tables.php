<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind')->default('one_time');
            // Conceptos del sistema: 'monthly_fee' (cuota) y 'enrollment_fee' (inscripción).
            $table->string('code')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_concept_id')->constrained()->restrictOnDelete();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            // Null: vale para todas las categorías.
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('valid_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['fee_concept_id', 'season_id', 'group_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariffs');
        Schema::dropIfExists('fee_concepts');
    }
};
