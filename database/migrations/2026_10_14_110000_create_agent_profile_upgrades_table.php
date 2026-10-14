<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approved individual executor's application to be raised to the legal-
 * entity track. The full legal KYC lives here until a manager approves it, so
 * the profile keeps working (Tezkor) under review; approval copies it onto the
 * same profile row (reputation and history stay with the executor). Rejected
 * rows are kept as history. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_profile_upgrades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');

            // Legal-entity KYC — mirrors the agent_profiles columns it is copied onto.
            $table->string('company_name');
            $table->string('legal_form');
            $table->string('inn');
            $table->string('legal_address', 300);
            $table->string('director_name');
            $table->string('director_pinfl');
            $table->string('director_position')->nullable();
            $table->string('director_passport');
            $table->foreignId('director_passport_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('registration_certificate_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('bank_name');
            $table->string('bank_account');
            $table->string('mfo');
            $table->string('phone');

            // Click-wrap acceptance of the agency partnership offer.
            $table->string('offer_version', 32)->nullable();
            $table->string('offer_hash', 64)->nullable();
            $table->timestamp('offer_accepted_at')->nullable();
            $table->string('offer_accepted_ip', 45)->nullable();

            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['agent_profile_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_profile_upgrades');
    }
};
