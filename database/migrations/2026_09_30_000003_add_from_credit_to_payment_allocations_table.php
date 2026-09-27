<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            // Imputada después, desde el saldo a favor: no forma parte del recibo original.
            $table->boolean('from_credit')->default(false)->after('early_payment_label');
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropColumn('from_credit');
        });
    }
};
