<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks whether a payout has been queued at Kapitalbank via SendPaymentIBK.
 * Per the bank's own guidance, this call only creates the payment order in
 * their internet-bank system — it does not move money. A human still signs
 * and sends it from the Kapitalbank website (OTP, batch confirm), which is
 * why this stays separate from `status`/`paid_at` (those change only once a
 * manager confirms the transfer actually happened, same as the manual flow).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->string('bank_uniq')->nullable()->after('reference');
            $table->string('bank_queue_status')->nullable()->after('bank_uniq'); // null | queued | failed
            $table->timestamp('bank_queued_at')->nullable()->after('bank_queue_status');
            $table->text('bank_queue_error')->nullable()->after('bank_queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropColumn(['bank_uniq', 'bank_queue_status', 'bank_queued_at', 'bank_queue_error']);
        });
    }
};
