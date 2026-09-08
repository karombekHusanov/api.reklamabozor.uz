<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the deal actually started (contract accepted → order in_progress).
 * Amendment windows are measured from here, so it must not drift with later
 * updates. Backfilled from the per-order contract's generation time, which is
 * created in the same activation transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('activated_at')->nullable()->after('payment_state');
        });

        // Backfill: contract generation time, else the order's last update.
        DB::table('orders')
            ->whereIn('status', [
                OrderStatus::InProgress->value,
                OrderStatus::WorkSubmitted->value,
                OrderStatus::Completed->value,
            ])
            ->orderBy('id')
            ->chunkById(200, function ($orders): void {
                foreach ($orders as $order) {
                    $generatedAt = DB::table('contracts')
                        ->where('order_id', $order->id)
                        ->value('generated_at');

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['activated_at' => $generatedAt ?? $order->updated_at]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('activated_at');
        });
    }
};
