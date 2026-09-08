<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Order\OrderActService;
use Illuminate\Console\Command;

/**
 * Backfills the acts for orders that completed before the documents existed,
 * so the bookkeeping has a closing document for every finished deal.
 */
class GenerateOrderActs extends Command
{
    protected $signature = 'orders:generate-acts {--limit=200 : How many orders to process in one run}';

    protected $description = 'Generate the missing acts (work + commission) for completed orders';

    public function handle(OrderActService $acts): int
    {
        $orders = Order::query()
            ->where('status', OrderStatus::Completed)
            ->whereDoesntHave('documents')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($orders->isEmpty()) {
            $this->info('Every completed order already has its acts.');

            return self::SUCCESS;
        }

        $generated = 0;

        foreach ($orders as $order) {
            try {
                $generated += count($acts->generateForOrder($order));
            } catch (\Throwable $e) {
                report($e);
                $this->warn("Order #{$order->id}: {$e->getMessage()}");
            }
        }

        $this->info("Generated {$generated} document(s) for {$orders->count()} order(s).");

        return self::SUCCESS;
    }
}
