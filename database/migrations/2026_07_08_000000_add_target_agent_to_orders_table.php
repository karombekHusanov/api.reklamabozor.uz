<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // When set, the order is directed to a single agency (chosen from its
            // public profile) instead of broadcast to every provider in the
            // category. Only this agent is notified and can send an offer.
            $table->foreignId('target_agent_id')->nullable()->after('client_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('target_agent_id');
        });
    }
};
