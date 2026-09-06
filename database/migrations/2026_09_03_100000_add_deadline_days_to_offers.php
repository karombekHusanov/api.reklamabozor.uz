<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent-committed delivery deadline (in days) sent alongside the pricelist.
 * Distinct from `orders.deadline` (the client's urgency preference) — this is
 * the provider's own promise and is snapshotted into the order contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('deadline_days')->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn('deadline_days');
        });
    }
};
