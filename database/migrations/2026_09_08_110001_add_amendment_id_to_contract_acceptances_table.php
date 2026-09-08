<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addenda are accepted the same way the contract itself is — click-wrap, logged
 * with the exact document. Rows with a null `amendment_id` remain the main
 * contract's acceptances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_acceptances', function (Blueprint $table): void {
            $table->foreignId('amendment_id')->nullable()->after('offer_id')
                ->constrained('order_amendments')->cascadeOnDelete();

            $table->index(['amendment_id', 'party']);
        });
    }

    public function down(): void
    {
        Schema::table('contract_acceptances', function (Blueprint $table): void {
            $table->dropIndex(['amendment_id', 'party']);
            $table->dropConstrainedForeignId('amendment_id');
        });
    }
};
