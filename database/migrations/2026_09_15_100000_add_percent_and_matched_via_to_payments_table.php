<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partial bank-transfer / cash payments: the client may settle 100% or 50% of
 * the then-current outstanding amount per offline payment. `percent` is
 * audit/display only — ledger math stays `dueTiyin() - paidTiyin()`.
 * `matched_via` will later distinguish `auto` (Kapitalbank auto-reconciliation,
 * a separate future task) from `admin` (manual confirm, this task).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedTinyInteger('percent')->nullable()->after('note');
            $table->string('matched_via', 16)->nullable()->after('percent');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['percent', 'matched_via']);
        });
    }
};
