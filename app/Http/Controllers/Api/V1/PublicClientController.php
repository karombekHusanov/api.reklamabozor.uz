<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Resources\PublicClientResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PublicClientController extends ApiController
{
    /**
     * Public client profile for providers (e.g. tapped from global chat).
     */
    public function show(User $user): JsonResponse
    {
        abort_unless($user->hasRole(Role::Client) && $user->is_active, 404);

        $inProgress = [
            OrderStatus::OffersSent,
            OrderStatus::ClientSelected,
            OrderStatus::InProgress,
            OrderStatus::WorkSubmitted,
        ];

        $user->load('avatarFile')
            ->loadCount([
                'orders',
                'orders as in_progress_orders_count' => fn ($query) => $query->whereIn('status', $inProgress),
                'orders as completed_orders_count' => fn ($query) => $query->where('status', OrderStatus::Completed),
                'orders as cancelled_orders_count' => fn ($query) => $query->where('status', OrderStatus::Cancelled),
                'reviews as approved_reviews_count' => fn ($query) => $query->approved(),
            ]);

        $user->setAttribute(
            'approved_reviews_avg_rating',
            $user->reviews()->approved()->avg('rating'),
        );

        return $this->success(new PublicClientResource($user));
    }
}
