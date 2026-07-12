<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Designers are individuals: their optional "studio name" lives in
        // company_name, and the public display name falls back to the user's
        // own name when it is empty. Agencies still must provide it (FormRequest).
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->string('company_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->string('company_name')->nullable(false)->change();
        });
    }
};
