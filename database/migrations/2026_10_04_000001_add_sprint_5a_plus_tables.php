<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5a+: clases suspendidas sin cobrar, reprogramación y avisos configurables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            // Suspendida sin cobrar (temporadas por día de entrenamiento).
            $table->boolean('charge_waived')->default(false)->after('suspended_by');
            // Reprogramada: la recuperación es otra clase del grupo.
            $table->foreignId('rescheduled_to_id')->nullable()->after('charge_waived')
                ->constrained('class_sessions')->nullOnDelete();
            $table->boolean('is_makeup')->default(false)->after('rescheduled_to_id');
        });

        // Ajuste "Clase suspendida" en una cuota: se sabe de qué clase vino para deshacerlo.
        Schema::table('charge_adjustments', function (Blueprint $table) {
            $table->foreignId('class_session_id')->nullable()->after('scholarship_id')
                ->constrained()->nullOnDelete();
        });

        // Descuento de una clase suspendida cuya cuota ya tenía pagos: va a la próxima cuota.
        Schema::create('charge_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('label');
            $table->foreignId('applied_charge_id')->nullable()->constrained('charges')->nullOnDelete();
            $table->timestamps();

            $table->unique(['class_session_id', 'enrollment_id']);
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('instructor_enabled')->default(true);
            // Null = los del club. Minutos antes o "eve" (día anterior 20:00).
            $table->json('instructor_offsets')->nullable();
            $table->json('guardian_offsets')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        // Un aviso por clase, destinatario, alumno (o ninguno, técnico) y momento.
        Schema::create('class_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 0 = aviso del técnico.
            $table->unsignedBigInteger('student_id')->default(0);
            $table->string('offset', 8);
            $table->timestamp('sent_at');

            $table->unique(['class_session_id', 'user_id', 'student_id', 'offset'], 'class_reminder_logs_unique');
        });

        Schema::table('attendances', fn (Blueprint $table) => $table->dropColumn('reminded_at'));

        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedTinyInteger('instructor_reminder_hours')->default(2)->after('class_reminder_hours');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('instructor_reminder_hours'));
        Schema::table('attendances', fn (Blueprint $table) => $table->timestamp('reminded_at')->nullable());
        Schema::dropIfExists('class_reminder_logs');
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('charge_waivers');
        Schema::table('charge_adjustments', fn (Blueprint $table) => $table->dropConstrainedForeignId('class_session_id'));
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rescheduled_to_id');
            $table->dropColumn(['charge_waived', 'is_makeup']);
        });
    }
};
