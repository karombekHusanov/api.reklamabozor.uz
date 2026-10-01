<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Additive only: older profiles keep working with these empty. */
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->string('legal_address', 300)->nullable()->after('inn');
            $table->string('director_pinfl', 14)->nullable()->after('director_name');
            $table->string('director_position', 100)->nullable()->after('director_pinfl');
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->dropColumn(['legal_address', 'director_pinfl', 'director_position']);
        });
    }
};
