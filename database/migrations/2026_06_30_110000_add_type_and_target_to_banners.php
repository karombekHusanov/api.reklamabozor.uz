<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table): void {
            // What the banner promotes; drives where a tap redirects.
            $table->string('type', 20)->default('agent')->after('subtitle');
            // Id of the promoted entity: agent_profiles.id (agent) or products.id (product).
            $table->unsignedBigInteger('target_id')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table): void {
            $table->dropColumn(['type', 'target_id']);
        });
    }
};
