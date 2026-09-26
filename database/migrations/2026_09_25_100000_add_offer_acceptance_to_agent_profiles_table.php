<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency partnership offer acceptance (click-wrap at KYC submit): which offer
 * version the agent accepted, when, and the hash of the exact text shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->string('offer_version', 32)->nullable()->after('contract_rejection_reason');
            $table->string('offer_hash', 64)->nullable()->after('offer_version');
            $table->timestamp('offer_accepted_at')->nullable()->after('offer_hash');
            $table->string('offer_accepted_ip', 45)->nullable()->after('offer_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->dropColumn(['offer_version', 'offer_hash', 'offer_accepted_at', 'offer_accepted_ip']);
        });
    }
};
