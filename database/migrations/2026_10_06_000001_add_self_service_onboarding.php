<?php

use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5d: alta autoservicio (cuenta con celular o correo, código por WhatsApp o correo, y club),
 * guía "Primeros pasos" y protección del envío de códigos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // La cuenta se identifica con el celular (WhatsApp) o con el correo.
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->timestamp('terms_accepted_at')->nullable()->after('phone_verified_at');
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');
        });

        // Las cuentas que ya existen entraron por invitación o por el seeder: verificadas.
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);

        // Un código vigente por usuario y propósito (verificar la cuenta o cambiar la contraseña);
        // el nuevo reemplaza al anterior.
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 10);
            $table->string('channel', 10);
            $table->string('destination');
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'purpose']);
        });

        // Cada código enviado (WhatsApp o correo): límites, tope diario y detector de abuso.
        Schema::create('verification_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 10);
            $table->string('purpose', 10);
            $table->string('destination');
            $table->string('country', 2)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['channel', 'created_at']);
            $table->index('destination');
        });

        // Teléfonos de los tutores en formato internacional (los que no se entienden quedan igual).
        DB::table('guardians')->whereNotNull('phone')->orderBy('id')->each(function (object $guardian): void {
            $phone = Phone::normalize($guardian->phone);
            if ($phone !== null && $phone !== $guardian->phone) {
                DB::table('guardians')->where('id', $guardian->id)->update(['phone' => $phone]);
            }
        });

        // Ajustes de la plataforma (ej. WhatsApp pausado).
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::table('organizations', function (Blueprint $table) {
            // Creada por su administrador (no desde la plataforma).
            $table->boolean('self_service')->default(false)->after('features');
            // Pasos que el admin dejó para después (claves del checklist).
            $table->json('onboarding_skipped')->nullable()->after('self_service');
            $table->timestamp('onboarding_dismissed_at')->nullable()->after('onboarding_skipped');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_dismissed_at');
        });

        // Las que ya funcionan no necesitan que la guía se abra sola.
        DB::table('organizations')->update(['onboarding_dismissed_at' => now()]);

        Schema::table('invitations', function (Blueprint $table) {
            // Una invitación va a un correo o a un celular (formato internacional).
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable()->after('email')->index();
            // Nombre que cargó quien invita (completa "Nombre y apellido" al aceptar).
            $table->string('name')->nullable()->after('phone');
            // Categorías del técnico: se asignan al aceptar.
            $table->json('group_ids')->nullable()->after('roles');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->dropColumn(['phone', 'name', 'group_ids']);
        });
        DB::table('invitations')->whereNull('email')->delete();
        Schema::table('invitations', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['self_service', 'onboarding_skipped', 'onboarding_dismissed_at', 'onboarding_completed_at']);
        });

        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('verification_sends');
        Schema::dropIfExists('verification_codes');

        DB::table('users')->whereNull('email')->delete();
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'phone_verified_at', 'terms_accepted_at', 'terms_version']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
