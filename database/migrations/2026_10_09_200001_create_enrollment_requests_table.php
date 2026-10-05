<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Solicitudes de inscripción que pide un tutor desde la app: el chico entra ya (inscripción pendiente, va a
        // clases y no se cobra) y el club la confirma o la rechaza.
        Schema::create('enrollment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('document', 20)->nullable();
            $table->date('birth_date');
            $table->string('relationship')->default('tutor');
            $table->foreignId('season_id')->constrained()->restrictOnDelete();
            $table->foreignId('group_id')->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            // Ficha médica opcional, cifrada: pasa a medical_records al aprobar y se borra al rechazar o cancelar.
            $table->text('medical')->nullable();
            $table->string('status')->default('pendiente');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            // Para deshacer al rechazar o cancelar: si el alumno y la inscripción los creó la solicitud, el estado
            // que tenía la inscripción antes (ej. baja) y si el tutor quedó vinculado ya o al confirmar.
            $table->boolean('student_created')->default(false);
            $table->string('previous_enrollment_status')->nullable();
            $table->boolean('guardian_linked')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_requests');
    }
};
