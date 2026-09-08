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
