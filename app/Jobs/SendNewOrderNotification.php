<?php

namespace App\Jobs;

use App\Enums\OrderRoute;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells ONE agent about a new order. Queued per recipient (staggered by
 * OrderNotifier) so a big audience is paced under Telegram's flood limit and
 * a failed send is retried on its own with backoff — nobody is skipped
 * silently. Unique per (order, agent), so a re-dispatch never double-sends.
 */
class SendNewOrderNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Attempts are bounded by real failures and by time, not by 429 waits:
    // a released flood-control wait must never use up the retry budget.
    public int $tries = 0;

    public int $maxExceptions = 6;

    public int $uniqueFor = 86400;

    public function __construct(
        public readonly int $orderId,
        public readonly int $recipientId,
    ) {
        $this->afterCommit();
    }

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [10, 30, 90, 300, 900];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function uniqueId(): string
    {
        return "new-order:{$this->orderId}:{$this->recipientId}";
    }

    public function handle(OrderNotifier $notifier): void
    {
        $order = Order::query()->find($this->orderId);
        $recipient = User::query()->find($this->recipientId);

        // The order may have moved on while this job waited its turn.
        if ($order === null || $recipient === null || ! $order->status->isOpenForOffers()) {
            return;
        }

        if ($order->route === OrderRoute::Tezkor && $order->isClaimed()) {
            return;
        }

        $response = $notifier->deliverNewOrder($order, $recipient);

        if ($response === null || $response->successful()) {
            return;
        }

        // Flood control: wait exactly as long as Telegram asks, then retry.
        if ($response->status() === 429) {
            $this->release(max(1, (int) $response->json('parameters.retry_after', 5)) + 1);

            return;
        }

        // The agent blocked the bot / closed the chat — retrying cannot help.
        if ($response->status() === 403) {
            Log::info('new_order.notify.chat_unreachable', ['order' => $order->id, 'user' => $recipient->id]);

            return;
        }

        throw new \RuntimeException("Telegram returned HTTP {$response->status()} for new-order notification.");
    }

    public function failed(Throwable $e): void
    {
        Log::warning('new_order.notify.failed', [
            'order' => $this->orderId,
            'user' => $this->recipientId,
            'error' => $e->getMessage(),
        ]);
    }
}
