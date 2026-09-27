<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            // ISO: 1 = lunes … 7 = domingo.
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
