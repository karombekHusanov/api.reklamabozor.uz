<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class OrderService
{
    public function __construct(
        private readonly OrderNotifier $notifier,
    ) {}

    /**
     * Place a B2C order. The title is derived from the category, and approved
     * providers serving that category are notified via the bot.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $client, array $data): Order
    {
        /** @var Category $category */
        $category = Category::findOrFail($data['category_id']);

        /** @var Order $order */
        $order = $client->orders()->create([
            'category_id' => $category->id,
            'title' => $category->name_uz,
            'description' => $data['description'],
            'deadline' => $data['deadline'] ?? null,
            'tz_file_id' => $data['tz_file_id'],
            'attachment_file_ids' => $data['attachment_file_ids'] ?? null,
            'status' => OrderStatus::New,
        ]);

        try {
            $this->notifier->notifyNewOrder($order);
        } catch (\Throwable $e) {
            report($e);
        }

        return $order->load(Order::CLIENT_RELATIONS);
    }

    /**
     * @return Collection<int, Order>
     */
    public function listForClient(User $client): Collection
    {
        return $client->orders()
            ->with('category')
            ->withCount(['offers', 'views'])
            ->latest()
            ->get();
    }

    public function findForClient(User $client, Order $order): Order
    {
        abort_unless($order->client_id === $client->id, 404);

        return $order->load(Order::CLIENT_RELATIONS)->loadCount(['offers', 'views']);
    }
}
