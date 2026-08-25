<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the order's category type (agent | designer) at creation time.
 *
 * Capacity statistics ("completed as agent / as designer") group orders by this
 * value. Reading the live `category.type` instead would corrupt history if a
 * category's type is ever changed or the order's category is reassigned, so we
 * freeze it on the order. Backfills existing rows from their current category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('category_type', 16)->nullable()->after('category_id');
            $table->index('category_type');
        });

        // Freeze the current category type onto every existing order.
        DB::table('orders')->whereNull('category_type')->orderBy('id')->chunkById(500, function ($orders): void {
            foreach ($orders as $order) {
                $type = DB::table('categories')->where('id', $order->category_id)->value('type');
                if ($type !== null) {
                    DB::table('orders')->where('id', $order->id)->update(['category_type' => $type]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['category_type']);
            $table->dropColumn('category_type');
        });
    }
};
