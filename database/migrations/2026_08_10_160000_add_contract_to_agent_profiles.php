<?php

use App\Enums\AgentContractStatus;
use App\Enums\AgentProfileStatus;
use App\Enums\ProviderType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform ↔ agent agreement: generated from KYC, signed offline, re-uploaded,
 * manager-approved before the agent can operate. Fields live on agent_profiles.
 * Existing approved agents are grandfathered to `approved` so the new gate does
 * not lock them out. Designer profiles never require a contract (null status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->string('contract_status')->nullable()->after('rejection_reason');
            $table->foreignId('contract_file_id')->nullable()->after('contract_status')
                ->constrained('files')->nullOnDelete();
            $table->string('contract_hash')->nullable()->after('contract_file_id');
            $table->string('contract_version')->nullable()->after('contract_hash');
            $table->timestamp('contract_generated_at')->nullable()->after('contract_version');
            $table->foreignId('signed_contract_file_id')->nullable()->after('contract_generated_at')
                ->constrained('files')->nullOnDelete();
            $table->timestamp('contract_signed_at')->nullable()->after('signed_contract_file_id');
            $table->text('contract_rejection_reason')->nullable()->after('contract_signed_at');
        });

        // Grandfather already-approved agents: they operate without re-signing.
        DB::table('agent_profiles')
            ->where('status', AgentProfileStatus::Approved->value)
            ->where('provider_type', ProviderType::Agent->value)
            ->update(['contract_status' => AgentContractStatus::Approved->value]);
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contract_file_id');
            $table->dropConstrainedForeignId('signed_contract_file_id');
            $table->dropColumn([
                'contract_status',
                'contract_hash',
                'contract_version',
                'contract_generated_at',
                'contract_signed_at',
                'contract_rejection_reason',
            ]);
        });
    }
};
