<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            // Immutable provider kind, set once at creation. Defaults to 'agent'
            // so existing rows and any legacy code keep the previous behaviour.
            $table->string('provider_type')->default('agent')->after('user_id');
        });

        // Backfill to match the old derived value (user.role === designer ? designer : agent),
        // so existing profiles render identically after the switch.
        $designerUserIds = DB::table('users')->where('role', 'designer')->pluck('id');

        if ($designerUserIds->isNotEmpty()) {
            DB::table('agent_profiles')
                ->whereIn('user_id', $designerUserIds)
                ->update(['provider_type' => 'designer']);
        }
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropColumn('provider_type');
        });
    }
};
