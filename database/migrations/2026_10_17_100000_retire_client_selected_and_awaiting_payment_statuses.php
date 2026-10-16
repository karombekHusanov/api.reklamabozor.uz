<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-only: the `client_selected` and `awaiting_payment` order statuses were
 * retired (audit 2026-10-16 §3.1). No row carried either value in prod or dev
 * at the time, so this is a safety net for any stray row:
 *  - client_selected  → offers_sent (the client never activated a deal);
 *  - awaiting_payment → in_progress (an accepted offer whose activation the
 *    old flow deferred until payment; the ledger keeps payment_state=unpaid).
 * Additive — no schema change, nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')->where('status', 'client_selected')->update(['status' => 'offers_sent']);
        DB::table('orders')->where('status', 'awaiting_payment')->update(['status' => 'in_progress']);
    }

    public function down(): void
    {
        // The original values cannot be told apart afterwards; intentionally a no-op.
    }
};
