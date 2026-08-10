<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shartnoma Faza A — pricelist line items an agent sends on an offer.
 * The offer's `price` column caches the sum of these rows (quantity * unit_price)
 * so the existing accept → payment → payout path stays unchanged. `mxik_code` /
 * `vat_rate` are reserved for the later fiscal/OFD contract (nullable for now).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit')->default('dona');
            $table->decimal('quantity', 15, 3)->default(1);
            $table->decimal('unit_price', 15, 2);
            // Reserved for the fiscal contract (Faza B / OFD) — unused in MVP.
            $table->string('mxik_code')->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['offer_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_items');
    }
};
