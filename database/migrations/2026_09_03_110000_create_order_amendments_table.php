<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Additional agreement" (Qo'shimcha kelishuv) — a proposed change to an active
 * deal's pricelist and/or deadline that requires multi-party approval before it
 * takes effect. Snapshots the before/after so the record is self-contained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiator_id')->constrained('users')->cascadeOnDelete();
            $table->string('initiator_role', 16); // client | agent

            $table->json('before_snapshot'); // { items, deadline_days, total }
            $table->json('after_snapshot');
            $table->string('reason', 500)->nullable();

            // after.total - before.total; positive => client owes extra.
            $table->decimal('extra_amount', 14, 2)->default(0);
            $table->boolean('requires_operator')->default(false);
            $table->boolean('requires_formal_doc')->default(false);

            $table->string('status', 16)->default('pending');
            $table->timestamp('client_approved_at')->nullable();
            $table->timestamp('agent_approved_at')->nullable();
            $table->timestamp('operator_approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable();

            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('pdf_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('hash', 64)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_amendments');
    }
};
