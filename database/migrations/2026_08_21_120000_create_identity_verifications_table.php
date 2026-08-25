<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional MyID (biometric) identity verification. Verifying is never required
 * to use the app — it only unlocks the "identity verified" badge. One record
 * per user; the returned government profile fields are stored so the badge and
 * (later) KYC prefill can rely on them without re-calling MyID.
 *
 * Personal fields (pinfl / pass_data / verified_full_name) are sensitive — keep
 * them minimal and never expose them beyond the owner + admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending|verified|failed
            // MyID web session handle (returned by createSession, used by the iframe).
            $table->string('session_id')->nullable();
            // Reusable MyID identifier — lets a returning person skip re-verifying.
            $table->string('myid_reuid')->nullable();
            // Government-sourced profile (only present after a successful check).
            $table->string('pinfl', 14)->nullable();
            $table->string('verified_full_name')->nullable();
            $table->string('pass_data', 32)->nullable(); // passport series+number
            // Face-match confidence (0.5–1.0 = success).
            $table->decimal('comparison_value', 4, 3)->nullable();
            // Diagnostics for the last failed attempt.
            $table->integer('failure_code')->nullable();
            $table->string('failure_note')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('pinfl');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
