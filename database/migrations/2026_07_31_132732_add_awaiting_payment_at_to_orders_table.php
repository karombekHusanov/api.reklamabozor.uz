<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // When the order entered awaiting_payment (offer accepted, gateway on).
            // Used by the unpaid-order timeout sweep; null for offline / unpaid flows.
            $table->timestamp('awaiting_payment_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('awaiting_payment_at');
        });
    }
};
