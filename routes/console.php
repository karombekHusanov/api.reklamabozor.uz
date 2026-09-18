<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Completion handshake sweep: day-2 reminders + day-3 auto-complete.
Schedule::command('orders:process-completions')->hourly();

// Settle payments the single Multicard callback left pending (progress→success).
Schedule::command('payments:reconcile-pending')->everyMinute()->withoutOverlapping();

// Additional agreements: nudge before the deadline, close what nobody answered.
Schedule::command('amendments:sweep')->hourly()->withoutOverlapping();

// Active deal, payment past due → remind client + agent + ops (no cancel).
Schedule::command('orders:remind-unpaid')->dailyAt('10:00');

// Legacy awaiting_payment orders: unpaid checkout window elapsed → cancel order
// + best-effort invoice DELETE.
Schedule::command('orders:cancel-expired-awaiting-payments')->hourly()->withoutOverlapping();

// Cooling-off window closed → tell ops which agent payouts are ready for their
// bank transfer (the gateway cannot send money to a settlement account).
Schedule::command('payouts:notify-due')->hourly()->withoutOverlapping();

// Poll Kapitalbank's statement and auto-confirm bank-transfer payments whose
// contract number + exact amount match an incoming transfer. No-op until
// KAPITALBANK_ENABLED=true, so this is safe to schedule unconditionally.
Schedule::command('orders:reconcile-bank-payments')->everyFiveMinutes()->withoutOverlapping();

// Queue releasable agent payouts as unsigned orders at Kapitalbank (a manager
// still signs/sends them from the bank's website). No-op until
// PAYOUT_BANK_QUEUE_ENABLED=true.
Schedule::command('payouts:queue-bank-transfers')->hourly()->withoutOverlapping();

// Quality dispute: nudge the agent a day before the correction window closes,
// then flag into the problem-orders admin queue if it still elapsed without
// the order reaching completed.
Schedule::command('orders:sweep-quality-disputes')->dailyAt('09:00')->withoutOverlapping();

// Order sat open-for-offers with zero offers too long → one-time reminder to
// the client (and ops) — no automatic re-broadcast or cancellation.
Schedule::command('orders:remind-stale')->dailyAt('10:30')->withoutOverlapping();
