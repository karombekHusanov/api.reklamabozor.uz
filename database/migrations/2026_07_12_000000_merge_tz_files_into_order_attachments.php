<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->whereNotNull('tz_file_id')
            ->orderBy('id')
            ->lazy()
            ->each(function (object $order): void {
                /** @var list<int> $ids */
                $ids = json_decode($order->attachment_file_ids ?? '[]', true) ?: [];
                $tzId = (int) $order->tz_file_id;

                if (! in_array($tzId, $ids, true)) {
                    array_unshift($ids, $tzId);
                }

                DB::table('orders')->where('id', $order->id)->update([
                    'attachment_file_ids' => json_encode($ids),
                    'tz_file_id' => null,
                ]);
            });
    }

    public function down(): void
    {
        // Irreversible: legacy TZ files are merged into attachment_file_ids.
    }
};
