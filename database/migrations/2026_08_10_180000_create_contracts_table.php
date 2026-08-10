<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-order service contract (client ↔ agent), generated once the deal starts
 * (order → in_progress). An immutable record: party requisites and line items
 * are snapshotted at generation time so the document never drifts if the
 * underlying profile/order later changes. One contract per order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number');
            $table->decimal('total', 15, 2)->default(0);
            // Frozen at generation time — the document must not drift afterwards.
            $table->json('client_snapshot');
            $table->json('agent_snapshot');
            $table->json('items_snapshot');
            $table->foreignId('pdf_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('hash')->nullable();
            $table->string('version')->default('v1');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
