<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\GatewayPaymentPurpose;
use App\Enums\GatewayPaymentStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Agent\ConfirmCardPaymentRequest;
use App\Http\Requests\Api\V1\Agent\StartCardPaymentRequest;
use App\Http\Requests\Api\V1\Agent\StartWalletTopupRequest;
use App\Http\Resources\AgentPassResource;
use App\Models\AgentPass;
use App\Models\GatewayPayment;
use App\Models\WalletTransaction;
use App\Services\Pass\CardPaymentService;
use App\Services\Pass\GatewayPaymentService;
use App\Services\Pass\PassService;
use App\Services\Pass\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Agent-facing Propusk (daily pass) + dormant wallet endpoints. */
class PassController extends ApiController
{
    public function __construct(
        private readonly PassService $passes,
        private readonly WalletService $wallet,
        private readonly GatewayPaymentService $gateway,
        private readonly CardPaymentService $cards,
    ) {}

    public function show(Request $request): JsonResponse
    {
        // The mini app polls this after opening checkout; ATMOS does not push
        // a paid notification, so settle the user's pending payments here
        // (throttled per payment so polling cannot hammer the provider).
        GatewayPayment::query()
            ->where('user_id', $request->user()->id)
            ->where('status', GatewayPaymentStatus::Pending)
            ->where('gateway', '!=', 'fake')
            ->where('created_at', '>', now()->subHours(2))
            ->get()
            ->each(function (GatewayPayment $p): void {
                if (Cache::add("gateway.sync.{$p->id}", 1, 3)) {
                    $this->gateway->sync($p);
                }
            });

        return $this->success($this->passes->summary($request->user()));
    }

    public function purchase(Request $request): JsonResponse
    {
        $agent = $request->user();

        if ($agent->approvedProfile() === null) {
            return $this->error('Only approved providers can buy a Propusk.', 403);
        }

        $result = $this->passes->purchase($agent);

        return $this->success([
            'activated' => $result['activated'],
            'checkout_url' => $result['checkout_url'],
            'payment_ref' => $result['payment_ref'],
            'pass' => $result['pass'] !== null ? new AgentPassResource($result['pass']) : null,
            'summary' => $this->passes->summary($agent),
        ], $result['activated'] ? 'Propusk activated' : 'Payment created', 201);
    }

    /**
     * In-app card form, step 1: card + expiry. The provider texts an SMS code
     * to the cardholder; the returned reference is confirmed in step 2.
     */
    public function cardStart(StartCardPaymentRequest $request): JsonResponse
    {
        $agent = $request->user();

        if ($agent->approvedProfile() === null) {
            return $this->error('Only approved providers can buy a Propusk.', 403);
        }

        $payment = $this->cards->startPass(
            $agent,
            (string) $request->validated('card_number'),
            $request->expiryYymm(),
        );

        return $this->success([
            'payment_ref' => $payment->reference,
            'amount_som' => intdiv($payment->amount_tiyin, 100),
            'card_mask' => $payment->meta['card_mask'] ?? null,
        ], 'SMS code sent', 201);
    }

    /**
     * Wallet top-up from the in-app card form (per-otklik mode). Confirmed the
     * same way as a Propusk card payment, via {@see cardConfirm()}.
     */
    public function walletCardStart(StartWalletTopupRequest $request): JsonResponse
    {
        if (! config('passes.wallet_enabled')) {
            return $this->error('The wallet is not enabled.', 422);
        }

        $agent = $request->user();

        if ($agent->approvedProfile() === null) {
            return $this->error('Only approved providers can top up the balance.', 403);
        }

        $payment = $this->cards->startTopup(
            $agent,
            (int) $request->validated('amount_som') * 100,
            (string) $request->validated('card_number'),
            $request->expiryYymm(),
        );

        return $this->success([
            'payment_ref' => $payment->reference,
            'amount_som' => intdiv($payment->amount_tiyin, 100),
            'card_mask' => $payment->meta['card_mask'] ?? null,
        ], 'SMS code sent', 201);
    }

    /** Step 2: the SMS code. On success the pass is active / the balance credited in the returned summary. */
    public function cardConfirm(ConfirmCardPaymentRequest $request, string $reference): JsonResponse
    {
        $agent = $request->user();
        $payment = $this->cards->confirm($agent, $reference, (string) $request->validated('otp'));

        return $this->success([
            'status' => $payment->status->value,
            'summary' => $this->passes->summary($agent),
        ], $payment->status === GatewayPaymentStatus::Success ? 'Propusk activated' : 'Payment pending');
    }

    public function history(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $paginator = AgentPass::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate($perPage);

        return $this->success([
            'items' => AgentPassResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** Wallet balance + latest ledger rows (only meaningful when the wallet is enabled). */
    public function wallet(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'enabled' => (bool) config('passes.wallet_enabled'),
            'balance_som' => intdiv($this->wallet->balanceTiyin($user), 100),
            'transactions' => WalletTransaction::query()->where('user_id', $user->id)->latest('id')->limit(50)->get()
                ->map(fn (WalletTransaction $t) => [
                    'id' => $t->id,
                    'type' => $t->type->value,
                    'amount_som' => intdiv($t->amount_tiyin, 100),
                    'note' => $t->note,
                    'created_at' => $t->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /** Top the wallet up through the gateway (wallet mode only). */
    public function topup(Request $request): JsonResponse
    {
        if (! config('passes.wallet_enabled')) {
            return $this->error('The wallet is not enabled.', 422);
        }

        $data = $request->validate(['amount_som' => ['required', 'integer', 'min:1', 'max:100000000']]);

        $payment = $this->gateway->start($request->user(), GatewayPaymentPurpose::Topup, $data['amount_som'] * 100);

        return $this->success([
            'checkout_url' => $payment->checkout_url,
            'payment_ref' => $payment->reference,
        ], 'Payment created', 201);
    }
}
