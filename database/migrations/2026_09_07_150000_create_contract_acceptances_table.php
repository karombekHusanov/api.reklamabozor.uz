<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Click-wrap consent log for the per-order (three-party) service contract.
 *
 * The agent accepts when sending the pricelist (offer = oferta), the client
 * accepts when picking that offer (aksept). Each row freezes the exact document
 * the party saw — parties, line items, total, clause text version — plus a hash,
 * so the binding moment is provable even if the offer is later revised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('party', 16); // agent | client

            $table->string('version', 16)->default('v1');       // contract template
            $table->string('terms_version', 16)->nullable();     // public offer in force
            $table->decimal('total', 15, 2)->default(0);
            // The document exactly as shown in the accept drawer.
            $table->json('snapshot');
            $table->string('hash', 64);

            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['offer_id', 'party']);
            $table->index(['order_id', 'party']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_acceptances');
    }
};
