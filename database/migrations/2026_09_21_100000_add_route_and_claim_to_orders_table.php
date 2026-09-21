<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Existing orders all ran the old tender-style flow.
            $table->string('route', 20)->default('tender')->after('status')->index();
            $table->foreignId('claimed_agent_id')->nullable()->after('route')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('claimed_agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('claimed_agent_id');
            $table->dropColumn(['route', 'claimed_at']);
        });
    }
};
