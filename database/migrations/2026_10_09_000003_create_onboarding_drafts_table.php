<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borradores de los pasos de "Primeros pasos" (lo que se está armando y todavía no se creó), para
 * retomarlos desde cualquier dispositivo, en la app o en el panel (`StepDrafts`). No se borran: al
 * crear lo que pedían (o al vaciarlos) quedan como usados (soft delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('step', 30);
            $table->json('draft');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'step', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_drafts');
    }
};
