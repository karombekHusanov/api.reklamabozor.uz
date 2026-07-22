<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional legal-entity verification for self-declared legal clients/designers.
 * Verifying is not required to use the app — it just unlocks the "verified"
 * badge (and, later, legal-entity benefits). One request per user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_entity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('inn', 20); // STIR
            $table->string('company_name')->nullable();
            $table->foreignId('registration_certificate_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('status', 16)->default('pending'); // pending|approved|rejected
            $table->text('rejection_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_entity_verifications');
    }
};
