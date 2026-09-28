<?php

namespace Tests\Feature\Payment;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\AgentProfile;
use App\Models\GatewayPayment;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\Pass\GatewayPaymentService;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Every payment / refund is posted to the dedicated payments Telegram group. */
class PaymentFeedTest extends TestCase
{
    use RefreshDatabase;

    private const FEED = '-100555';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.payments_chat_id' => self::FEED,
            'services.telegram.admin_chat_id' => '',
            'passes.gateway' => 'fake', 'passes.wallet_enabled' => true, 'passes.response_price_som' => 1000,
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);
        Storage::fake((string) config('files.disk'));
    }

    /** @return list<string> texts sent to the payments group */
    private function feed(): array
    {
        return Http::recorded()
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/sendMessage') && (string) ($pair[0]['chat_id'] ?? '') === self::FEED)
            ->map(fn ($pair) => (string) $pair[0]['text'])
            ->values()->all();
    }

    private function cardTopUp(User $agent): GatewayPayment
    {
        $token = $agent->createToken('t')->plainTextToken;
        $ref = $this->withToken($token)->postJson('/api/v1/agent/wallet/card', [
            'amount_som' => 10000, 'card_number' => '8600 1234 5678 2365', 'expiry' => '01/28',
        ])->assertCreated()->json('data.payment_ref');
        $this->withToken($token)->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '111111'])->assertOk();

        return GatewayPayment::query()->where('reference', $ref)->firstOrFail();
    }

    public function test_card_top_up_and_its_refund_are_posted(): void
    {
        $agent = User::factory()->create(['phone' => '+998901112233']);
        AgentProfile::factory()->for($agent)->approved()->create(['company_name' => 'Nova Media']);

        $payment = $this->cardTopUp($agent);

        $this->assertCount(1, $this->feed());
        $text = $this->feed()[0];
        $this->assertStringContainsString("+10 000 so'm", $text);
        $this->assertStringContainsString("Balansni to'ldirish", $text);
        $this->assertStringContainsString('Nova Media', $text);
        $this->assertStringContainsString('8600 •••• 2365', $text);

        app(GatewayPaymentService::class)->refund($payment, User::factory()->admin()->create(), 'xato to‘lov');

        $this->assertCount(2, $this->feed());
        $this->assertStringContainsString("−10 000 so'm</b> qaytarildi", $this->feed()[1]);
        $this->assertStringContainsString('xato to‘lov', $this->feed()[1]);
    }

    public function test_failed_card_attempts_are_not_posted(): void
    {
        $agent = User::factory()->create();
        AgentProfile::factory()->for($agent)->approved()->create();
        $token = $agent->createToken('t')->plainTextToken;

        // Card ending in 0000 is declined by the fake gateway.
        $this->withToken($token)->postJson('/api/v1/agent/wallet/card', [
            'amount_som' => 10000, 'card_number' => '8600 1234 5678 0000', 'expiry' => '01/28',
        ])->assertStatus(422);

        $this->assertSame([], $this->feed());
    }

    public function test_order_payment_confirm_and_refund_are_posted(): void
    {
        $client = User::factory()->create(['first_name' => 'Aziza', 'last_name' => null]);
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Unpaid, 'payment_due_at' => now()->addDays(3),
        ]);
        $profile = AgentProfile::factory()->create();
        Offer::factory()->for($order)->create([
            'status' => OfferStatus::Accepted, 'price' => 2_000_000,
            'agent_id' => $profile->user_id, 'agent_profile_id' => $profile->id,
        ]);

        $payments = app(PaymentService::class);
        $payment = $payments->startOfflineOrderPayment($order->fresh(), PaymentMethod::BankTransfer, 50);
        $this->assertSame([], $this->feed()); // an invoice alone is not money

        $admin = User::factory()->admin()->create(['first_name' => 'Operator']);
        $payments->confirmOfflinePayment($payment, $admin, 'PP-7');

        $text = $this->feed()[0];
        $this->assertStringContainsString("+1 000 000 so'm</b> · Buyurtma #{$order->id} (50%)", $text);
        $this->assertStringContainsString("Bank o'tkazmasi · Admin tasdiqladi: Operator", $text);
        $this->assertStringContainsString('Aziza', $text);

        // A replayed confirm posts nothing new.
        $payments->confirmOfflinePayment($payment->fresh(), $admin, 'PP-7');
        $this->assertCount(1, $this->feed());

        $payments->refundByAdmin($payment->fresh(), $admin);
        $this->assertStringContainsString("−1 000 000 so'm</b> qaytarildi · Buyurtma #{$order->id}", $this->feed()[1]);
    }

    public function test_bank_auto_match_is_labelled(): void
    {
        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Unpaid, 'payment_due_at' => now()->addDays(3),
        ]);
        $profile = AgentProfile::factory()->create();
        Offer::factory()->for($order)->create([
            'status' => OfferStatus::Accepted, 'price' => 500_000,
            'agent_id' => $profile->user_id, 'agent_profile_id' => $profile->id,
        ]);
        $payments = app(PaymentService::class);
        $payment = $payments->startOfflineOrderPayment($order->fresh(), PaymentMethod::BankTransfer);

        $payments->confirmBankTransferAutoMatch($payment, ['purpose' => 'RB-1-2026 uchun']);

        $this->assertStringContainsString("Kapitalbank ko'chirmasidan avtomatik topildi", $this->feed()[0]);
    }

    public function test_nothing_is_posted_without_a_payments_group(): void
    {
        config(['services.telegram.payments_chat_id' => '']);
        $agent = User::factory()->create();
        AgentProfile::factory()->for($agent)->approved()->create();

        $this->cardTopUp($agent);

        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendMessage') && (string) ($r['chat_id'] ?? '') === self::FEED);
    }

    public function test_bot_added_to_a_group_logs_the_chat_id(): void
    {
        Log::spy();
        config(['services.telegram.webhook_secret' => 'wh']);

        $this->postJson('/api/v1/telegram/webhook', [
            'update_id' => 1,
            'my_chat_member' => [
                'chat' => ['id' => -1009998887776, 'title' => "PRB — To'lovlar", 'type' => 'supergroup'],
                'from' => ['id' => 42],
                'new_chat_member' => ['status' => 'member'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'wh'])->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($msg, $ctx = []) => $msg === 'telegram.bot_group_membership' && ($ctx['chat_id'] ?? null) === -1009998887776)
            ->once();
    }
}
