<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records each user's acceptance of the platform public offer (Terms of Use),
 * versioned. When the current version (config legal.terms_version) no longer
 * matches the accepted one, the user is re-prompted. No backfill: existing users
 * re-accept the current version once on their next visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('accepted_terms_version')->nullable()->after('role_selected_at');
            $table->timestamp('accepted_terms_at')->nullable()->after('accepted_terms_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['accepted_terms_version', 'accepted_terms_at']);
        });
    }
};
