<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja de avisos de la app: cada aviso (`PushNotification`) queda guardado con la organización
 * en la que se mandó, para mostrar en cada organización solo los suyos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('notifiable_id')->constrained()->nullOnDelete();
            $table->index(['notifiable_type', 'notifiable_id', 'organization_id', 'created_at'], 'notifications_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_inbox_index');
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
