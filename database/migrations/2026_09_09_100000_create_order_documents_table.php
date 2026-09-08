<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting documents closing an order: the act of completed work
 * (client ↔ agent) and the platform's commission act (platform ↔ agent).
 * Immutable once generated — the snapshot is the evidence, the PDF its render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('number');
            $table->decimal('total', 14, 2)->default(0);
            $table->json('snapshot');
            $table->foreignId('pdf_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('hash', 64)->nullable();
            $table->string('version', 16)->default('v1');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_documents');
    }
};
