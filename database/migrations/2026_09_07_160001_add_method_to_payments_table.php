<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments gain a method: cash or bank transfer, an offline route a manager
 * confirms by hand (or, for bank transfer, Kapitalbank auto-reconciliation
 * matches). Offline rows carry the confirmation trail.
 *
 * NOTE: `short_link` below was for a hosted gateway checkout's shareable QR
 * link, no longer used; a later migration drops it. The `method` default
 * (originally the now-removed hosted-gateway case) is written as a literal
 * string rather than an enum reference so this migration keeps working after
 * that case was removed from `PaymentMethod` — every write path sets `method`
 * explicitly, so the stored default is never actually relied on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('method', 16)->default('bank_transfer')->index();
            // Hosted-checkout short link (no longer used) — QR source.
            $table->string('short_link')->nullable();
            // Generated invoice / payment slip handed to the client.
            $table->foreignId('invoice_file_id')->nullable()->constrained('files')->nullOnDelete();
            // Offline confirmation trail.
            $table->string('reference', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_file_id');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['method', 'short_link', 'reference', 'note', 'confirmed_at']);
        });
    }
};
