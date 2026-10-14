<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Tezkor only: pay_to_respond | pay_on_pick, frozen at creation
            // (null on older rows = pay_to_respond).
            $table->string('tezkor_billing', 20)->nullable()->after('route');
            // pay_on_pick: the otklik the client picked, waiting for the agent to pay.
            $table->foreignId('picked_offer_id')->nullable()->after('claimed_at')
                ->constrained('offers')->nullOnDelete();
            $table->timestamp('picked_at')->nullable()->after('picked_offer_id');
            $table->timestamp('pick_expires_at')->nullable()->after('picked_at');
            $table->index('pick_expires_at');
        });

        Schema::table('offers', function (Blueprint $table): void {
            // The per-order fee this agent paid for this order (otklik or take).
            $table->unsignedBigInteger('fee_tiyin')->nullable()->after('status');
            $table->timestamp('fee_paid_at')->nullable()->after('fee_tiyin');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn(['fee_tiyin', 'fee_paid_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('picked_offer_id');
            $table->dropIndex(['pick_expires_at']);
            $table->dropColumn(['tezkor_billing', 'picked_at', 'pick_expires_at']);
        });
    }
};
