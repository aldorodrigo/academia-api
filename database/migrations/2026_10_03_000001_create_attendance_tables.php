<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asistencia: clases (un día concreto de un horario del grupo), la marca y la
 * respuesta del tutor por alumno, el aviso de los días de clase y los
 * dispositivos para push.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('programada');
            $table->string('suspension_reason')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attendance_taken_at')->nullable();
            $table->foreignId('attendance_taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'date', 'starts_at']);
            $table->index(['organization_id', 'date']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            // Null = todavía no se tomó.
            $table->string('status')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            // "¿Lo llevás?": va / no_va.
            $table->string('guardian_response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['class_session_id', 'student_id']);
            $table->index(['student_id', 'status']);
        });

        // Aviso de los días de clase, por usuario (tutor o alumno adulto) y alumno.
        Schema::create('class_reminder_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'student_id']);
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 16);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('organizations', function (Blueprint $table) {
            // Horas antes de la clase en que sale el aviso "¿Lo llevás?".
            $table->unsignedTinyInteger('class_reminder_hours')->default(3)->after('billing');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('class_reminder_hours'));
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('class_reminder_preferences');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('class_sessions');
    }
};
