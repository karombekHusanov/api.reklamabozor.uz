<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sweep nudges the party that has not answered a proposal, once, shortly
 * before it runs out of time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_amendments', function (Blueprint $table): void {
            $table->timestamp('reminder_sent_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_amendments', function (Blueprint $table): void {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
