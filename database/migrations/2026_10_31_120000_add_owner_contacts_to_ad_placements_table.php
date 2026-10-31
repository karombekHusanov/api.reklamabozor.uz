<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner contacts as imported (phones, website, email) — shown openly on the
 * map card so a client can reach the screen owner directly while in-app
 * ordering for placements is switched off. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_placements', function (Blueprint $table): void {
            $table->json('owner_phones')->nullable()->after('external_ref');
            $table->string('owner_website', 200)->nullable()->after('owner_phones');
            $table->string('owner_email', 200)->nullable()->after('owner_website');
        });
    }

    public function down(): void
    {
        Schema::table('ad_placements', function (Blueprint $table): void {
            $table->dropColumn(['owner_phones', 'owner_website', 'owner_email']);
        });
    }
};
