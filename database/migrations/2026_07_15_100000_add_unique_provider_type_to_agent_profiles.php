<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            // A user holds at most one profile of each provider kind. Today the
            // app enforces a single provider profile per user; this constraint
            // makes the multi-profile future (e.g. agent + seller) structurally
            // safe without changing current behaviour.
            $table->unique(['user_id', 'provider_type']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'provider_type']);
        });
    }
};
