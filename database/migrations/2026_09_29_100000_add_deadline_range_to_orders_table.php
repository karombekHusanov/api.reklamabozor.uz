<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concrete work window the client picks on the calendar (from – to).
 * Additive; the `deadline` urgency preset stays for older orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('deadline_from')->nullable()->after('deadline');
            $table->date('deadline_to')->nullable()->after('deadline_from');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['deadline_from', 'deadline_to']);
        });
    }
};
