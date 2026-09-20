<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform dropped the hosted payment gateway in favour of manual cash /
 * bank-transfer payments (Kapitalbank auto-reconciliation for the latter).
 * This removes the now-dead gateway columns and the card-withdrawal table.
 * No real production users existed on the gateway flow at the time of this
 * change, so the handful of leftover gateway-method payment rows are purged
 * rather than migrated (a `method` cast to the trimmed `PaymentMethod` enum
 * would otherwise fatal on them).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Purge legacy gateway-method rows before dropping the columns —
        // `payments.method` is enum-cast and no longer has a matching case
        // for them.
        DB::table('payments')->where('method', 'multicard')->delete();

        Schema::table('payments', function (Blueprint $table): void {
            // SQLite refuses to drop an indexed column.
            $table->dropIndex(['gateway_uuid']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn([
                'checkout_url',
                'card_pan',
                'ps',
                'billing_id',
                'short_link',
                'gateway',
                'gateway_uuid',
            ]);
        });

        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('withdrawal_id');
            $table->dropColumn('gateway_uuid');
        });

        Schema::dropIfExists('withdrawals');
    }

    /**
     * Not reversible — the dropped columns' data is gone and the card
     * cash-out flow they backed no longer exists in the codebase to use them.
     */
    public function down(): void
    {
        //
    }
};
