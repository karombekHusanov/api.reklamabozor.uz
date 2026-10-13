<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual ("jismoniy") executor KYC: a selfie scan alongside the passport,
 * and an optional alternate contact. Additive only — existing agent/designer
 * profiles leave both null. provider_type is a string column, so the new
 * `individual` value needs no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->foreignId('selfie_file_id')->nullable()->after('director_passport_file_id')
                ->constrained('files')->nullOnDelete();
            $table->string('alt_contact', 100)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('selfie_file_id');
            $table->dropColumn('alt_contact');
        });
    }
};
