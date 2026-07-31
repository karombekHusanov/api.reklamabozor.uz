<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            // The withdrawal that released this payout to the agent's card
            // (null for manually-released or not-yet-withdrawn payouts).
            $table->foreignId('withdrawal_id')->nullable()->after('order_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('withdrawal_id');
        });
    }
};
