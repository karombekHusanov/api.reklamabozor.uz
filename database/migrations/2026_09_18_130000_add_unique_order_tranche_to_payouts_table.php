<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One payout per (order, tranche): makes concurrent planAdvance/planFinal
 * calls safe at the DB level — the second insert fails instead of duplicating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->unique(['order_id', 'tranche'], 'payouts_order_tranche_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropUnique('payouts_order_tranche_unique');
        });
    }
};
