<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An amendment stops being a loose change-set and becomes a numbered addendum
 * to the order's service contract: `RB-35-2026/DS1`, bound to that contract,
 * carrying the client's proposal window as it stood and a response deadline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_amendments', function (Blueprint $table): void {
            $table->string('number', 64)->nullable()->after('offer_id');
            $table->unsignedInteger('sequence')->nullable()->after('number');
            $table->foreignId('contract_id')->nullable()->after('sequence')
                ->constrained('contracts')->nullOnDelete();
            // The client window as it stood when this was proposed (audit).
            $table->timestamp('client_window_ends_at')->nullable()->after('initiator_role');
            // How long the other side has to answer.
            $table->timestamp('expires_at')->nullable()->after('client_window_ends_at');
            // Fingerprint of the accepted addendum text (click-wrap integrity).
            $table->string('document_hash', 64)->nullable()->after('hash');

            $table->unique(['order_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('order_amendments', function (Blueprint $table): void {
            $table->dropUnique(['order_id', 'sequence']);
            $table->dropConstrainedForeignId('contract_id');
            $table->dropColumn(['number', 'sequence', 'client_window_ends_at', 'expires_at', 'document_hash']);
        });
    }
};
