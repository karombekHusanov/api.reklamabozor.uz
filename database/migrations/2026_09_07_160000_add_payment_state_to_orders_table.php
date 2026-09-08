<?php

use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money moves onto its own track: accepting the contract now activates the deal
 * immediately, so an order can be in progress while the payment is still owed
 * (online, invoice link, cash or bank transfer). The order status keeps
 * describing the work; `payment_state` describes the money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_state', 16)->default(OrderPaymentState::NotRequired->value)->index();
            // When the client is expected to have paid (activation + grace).
            $table->timestamp('payment_due_at')->nullable();
            // Last overdue reminder, so the sweep nags at most once a day.
            $table->timestamp('payment_reminded_at')->nullable();
            $table->timestamp('paid_at')->nullable();
        });

        // Backfill: a settled order payment means paid; orders still parked in
        // the legacy awaiting_payment status owe money; everything else ran on
        // the offline flow where the platform collected nothing.
        $paidOrderIds = DB::table('payments')
            ->where('purpose', 'order')
            ->where('status', PaymentStatus::Success->value)
            ->where('payable_type', 'App\\Models\\Order')
            ->pluck('payable_id')
            ->unique()
            ->all();

        if ($paidOrderIds !== []) {
            DB::table('orders')->whereIn('id', $paidOrderIds)->update([
                'payment_state' => OrderPaymentState::Paid->value,
            ]);
        }

        DB::table('orders')
            ->where('status', OrderStatus::AwaitingPayment->value)
            ->whereNotIn('id', $paidOrderIds ?: [0])
            ->update(['payment_state' => OrderPaymentState::Unpaid->value]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['payment_state', 'payment_due_at', 'payment_reminded_at', 'paid_at']);
        });
    }
};
