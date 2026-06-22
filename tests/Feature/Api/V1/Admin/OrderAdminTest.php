<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAdminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: string}
     */
    private function admin(): array
    {
        $user = User::factory()->create(['role' => Role::Admin]);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_orders_admin_requires_admin_role(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $token = $client->createToken('test')->plainTextToken;

        // Non-admin (and guests) are blocked by the EnsureAdmin middleware with 403.
        $this->getJson('/api/v1/admin/orders', $this->auth($token))->assertForbidden();
        $this->getJson('/api/v1/admin/orders')->assertForbidden();
    }

    public function test_admin_can_list_orders(): void
    {
        [, $token] = $this->admin();
        Order::factory()->count(3)->create();

        $this->getJson('/api/v1/admin/orders', $this->auth($token))
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.meta.total', 3);
    }

    public function test_admin_can_filter_orders_by_status(): void
    {
        [, $token] = $this->admin();
        Order::factory()->status(OrderStatus::ClientSelected)->create();
        Order::factory()->status(OrderStatus::New)->count(2)->create();

        $this->getJson('/api/v1/admin/orders?status=client_selected', $this->auth($token))
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_admin_can_view_an_order_with_offers_and_client(): void
    {
        [, $token] = $this->admin();
        $order = Order::factory()->status(OrderStatus::ClientSelected)->create();
        Offer::factory()->for($order)->accepted()->create();
        Offer::factory()->for($order)->count(2)->create();

        $this->getJson("/api/v1/admin/orders/{$order->id}", $this->auth($token))
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonCount(3, 'data.offers')
            ->assertJsonPath('data.client.id', $order->client_id);
    }

    public function test_admin_can_activate_a_selected_order(): void
    {
        [, $token] = $this->admin();
        $order = Order::factory()->status(OrderStatus::ClientSelected)->create();

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'in_progress',
        ], $this->auth($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    public function test_admin_can_complete_an_in_progress_order(): void
    {
        [, $token] = $this->admin();
        $order = Order::factory()->status(OrderStatus::InProgress)->create();

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'completed',
        ], $this->auth($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_admin_cannot_make_an_invalid_transition(): void
    {
        [, $token] = $this->admin();
        // A brand-new order cannot jump straight to in_progress.
        $order = Order::factory()->status(OrderStatus::New)->create();

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'in_progress',
        ], $this->auth($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_admin_can_cancel_an_order(): void
    {
        [, $token] = $this->admin();
        $order = Order::factory()->status(OrderStatus::OffersSent)->create();

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'cancelled',
        ], $this->auth($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_status_value_is_validated(): void
    {
        [, $token] = $this->admin();
        $order = Order::factory()->status(OrderStatus::ClientSelected)->create();

        // "new" is not an admin-settable target.
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'new',
        ], $this->auth($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }
}
