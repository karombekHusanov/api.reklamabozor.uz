<?php

namespace App\Http\Resources;

use App\Services\Activity\UserActivityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin pass-through for the activity aggregate payload built by
 * {@see UserActivityService}. Ensures scalar counts
 * stay ints and whole blocks may be null.
 *
 * @property array<string, mixed> $resource
 */
class UserActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'role' => $data['role'],
            'roles' => $data['roles'],
            'agent_profile_id' => $data['agent_profile_id'],
            'chats' => [
                'unread_messages' => (int) $data['chats']['unread_messages'],
                'unread_threads' => (int) $data['chats']['unread_threads'],
                'order_unread_messages' => (int) $data['chats']['order_unread_messages'],
                'direct_unread_messages' => (int) $data['chats']['direct_unread_messages'],
                'global_unread' => (int) $data['chats']['global_unread'],
            ],
            'client' => $data['client'] === null ? null : $this->clientBlock($data['client']),
            'provider' => $data['provider'] === null ? null : $this->providerBlock($data['provider']),
            'live_orders' => [
                'count' => (int) $data['live_orders']['count'],
                'last_seen_at' => $data['live_orders']['last_seen_at'],
            ],
            'notifications' => [
                'unread' => (int) $data['notifications']['unread'],
            ],
            'action_required' => (int) $data['action_required'],
            'generated_at' => $data['generated_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>
     */
    private function clientBlock(array $client): array
    {
        $byStatus = [];
        foreach ($client['by_status'] as $status => $count) {
            $byStatus[$status] = (int) $count;
        }

        return [
            'orders_total' => (int) $client['orders_total'],
            'orders_open' => (int) $client['orders_open'],
            'orders_awaiting_payment' => (int) $client['orders_awaiting_payment'],
            'orders_in_progress' => (int) $client['orders_in_progress'],
            'orders_awaiting_confirmation' => (int) $client['orders_awaiting_confirmation'],
            'orders_completed' => (int) $client['orders_completed'],
            'orders_cancelled' => (int) $client['orders_cancelled'],
            'offers_received_pending' => (int) $client['offers_received_pending'],
            'reviews_pending' => (int) $client['reviews_pending'],
            'by_status' => $byStatus,
        ];
    }

    /**
     * @param  array<string, mixed>  $provider
     * @return array<string, mixed>
     */
    private function providerBlock(array $provider): array
    {
        return [
            'has_profile' => (bool) $provider['has_profile'],
            'profile_status' => $provider['profile_status'],
            'offers_total' => (int) $provider['offers_total'],
            'offers_pending' => (int) $provider['offers_pending'],
            'offers_accepted' => (int) $provider['offers_accepted'],
            'offers_rejected' => (int) $provider['offers_rejected'],
            'deals_awaiting_payment' => (int) ($provider['deals_awaiting_payment'] ?? 0),
            'deals_in_progress' => (int) $provider['deals_in_progress'],
            'deals_work_submitted' => (int) $provider['deals_work_submitted'],
            'deals_completed' => (int) $provider['deals_completed'],
            'reviews_pending' => (int) $provider['reviews_pending'],
            'portfolio_items' => (int) $provider['portfolio_items'],
            'categories' => (int) $provider['categories'],
        ];
    }
}
