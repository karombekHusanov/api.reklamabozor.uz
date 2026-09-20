<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central payment registry. One row per payment attempt. Kept generic —
 * `purpose` + polymorphic `payable` let the same table back order payments
 * today and future subscriptions, commissions, holds and payouts (see
 * PROJECT_LOGIC finance phases). Amounts are stored in **tiyin** (1 som =
 * 100 tiyin).
 *
 * NOTE: several columns below (`gateway`, `gateway_uuid`, `checkout_url`,
 * `card_pan`, `ps`, `billing_id`, `short_link`) were added for a hosted
 * payment gateway that the platform no longer uses; a later migration drops
 * them. Left as originally written (additive history), not edited here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // Our own identifier, sent to the gateway as `invoice_id` and used
            // to correlate callbacks. Random UUID, never reused.
            $table->uuid('payment_uuid')->unique();

            $table->string('gateway', 32)->default('');
            // Gateway-side transaction id. Null until the invoice is created.
            $table->string('gateway_uuid')->nullable()->index();

            $table->string('purpose', 32)->default('order'); // PaymentPurpose

            // What is being paid for (polymorphic — Order for now).
            $table->nullableMorphs('payable');

            $table->foreignId('payer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('amount'); // tiyin
            $table->string('currency', 3)->default('UZS');

            $table->string('status', 16)->default('draft'); // PaymentStatus

            $table->text('checkout_url')->nullable();
            $table->string('card_pan', 32)->nullable();
            $table->string('ps', 32)->nullable(); // payment system: uzcard|humo|visa...
            $table->string('billing_id')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['purpose', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
