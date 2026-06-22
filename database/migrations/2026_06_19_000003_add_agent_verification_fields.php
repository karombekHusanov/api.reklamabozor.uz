<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            // Legal-entity form (free text: YaTT, MChJ, AJ, ...).
            $table->string('legal_form', 100)->nullable()->after('company_name');

            // KYC document scans (referenced from the files registry).
            $table->foreignId('director_passport_file_id')
                ->nullable()
                ->after('director_passport')
                ->constrained('files')
                ->nullOnDelete();
            $table->foreignId('registration_certificate_file_id')
                ->nullable()
                ->after('director_passport_file_id')
                ->constrained('files')
                ->nullOnDelete();

            // Bank requisites for payouts / invoicing.
            $table->string('bank_name', 200)->nullable()->after('registration_certificate_file_id');
            $table->string('bank_account', 30)->nullable()->after('bank_name');
            $table->string('mfo', 10)->nullable()->after('bank_account');
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('director_passport_file_id');
            $table->dropConstrainedForeignId('registration_certificate_file_id');
            $table->dropColumn(['legal_form', 'bank_name', 'bank_account', 'mfo']);
        });
    }
};
