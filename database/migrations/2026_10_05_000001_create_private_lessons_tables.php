<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5c: clases particulares (reservas, clase suelta y paquetes de clases).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ajustes del profesor.
        Schema::create('lesson_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedInteger('single_price')->default(0);
            $table->unsignedInteger('min_notice_minutes')->default(120);
            $table->unsignedSmallInteger('days_ahead')->default(30);
            // Cuenta donde entra lo que cobra desde la app.
            $table->foreignId('money_account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        // Paquetes que ofrece el profesor (los que deja de ofrecer quedan inactivos).
        Schema::create('lesson_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('classes');
            $table->unsignedInteger('price');
            // Null = sin vencimiento. Cuenta desde que se paga.
            $table->unsignedSmallInteger('valid_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Franjas semanales en las que el profesor recibe reservas.
        Schema::create('availability_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'weekday']);
        });

        // Paquete comprado por un alumno: cantidad de clases, no plata.
        Schema::create('class_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_pack_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('classes');
            $table->unsignedSmallInteger('used')->default(0);
            $table->unsignedInteger('price');
            $table->unsignedSmallInteger('valid_days')->nullable();
            $table->string('status')->default('pendiente_pago');
            $table->date('activated_on')->nullable();
            $table->date('expires_on')->nullable();
            // Aviso de vencimiento ya enviado: 7 o 1 (días antes).
            $table->unsignedTinyInteger('expiry_notified')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'student_id', 'user_id', 'status']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('status')->default('confirmada');
            $table->foreignId('class_pack_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('price');
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            // "{profesor}:{fecha}:{hora}" mientras está activa; null al cancelarse. Evita la doble reserva.
            $table->string('slot_key')->nullable()->unique();
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'date']);
            $table->index(['organization_id', 'student_id', 'date']);
        });

        // Avisos de clases particulares ya enviados (idempotencia de classes:remind):
        // "bkg:{reserva}:u:{usuario}:{momento}" al alumno o "day:{fecha}:u:{usuario}:{momento}" al profesor.
        Schema::create('lesson_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reminder_key')->unique();
            $table->timestamp('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_reminder_logs');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('class_packs');
        Schema::dropIfExists('availability_slots');
        Schema::dropIfExists('lesson_packs');
        Schema::dropIfExists('lesson_profiles');
    }
};
