<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->foreignId('company_logo_file_id')
                ->nullable()
                ->after('company_name')
                ->constrained('files')
                ->nullOnDelete();

            // Legal-entity / KYC details for verification.
            $table->string('director_name', 200)->nullable()->after('company_logo_file_id');
            $table->string('inn', 9)->nullable()->after('director_name');
            $table->string('director_passport', 20)->nullable()->after('inn');
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_logo_file_id');
            $table->dropColumn(['director_name', 'inn', 'director_passport']);
        });
    }
};
