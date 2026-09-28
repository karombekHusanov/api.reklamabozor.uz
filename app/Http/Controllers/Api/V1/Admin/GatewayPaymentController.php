<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\GatewayPaymentPurpose;
use App\Enums\GatewayPaymentStatus;
use App\Http\Controllers\ApiController;
use App\Models\GatewayPayment;
use App\Services\Pass\GatewayPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Online (card) payments for Propusk and wallet top-ups: list + full refund.
 */
class GatewayPaymentController extends ApiController
{
    public function __construct(
        private readonly GatewayPaymentService $payments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status' => ['nullable', Rule::enum(GatewayPaymentStatus::class)],
            'purpose' => ['nullable', Rule::enum(GatewayPaymentPurpose::class)],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = GatewayPayment::query()
            ->with('user.profile')
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($v['purpose'] ?? null, fn ($q, $p) => $q->where('purpose', $p))
            ->when($v['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->latest('id')
            ->paginate($v['per_page'] ?? 20);

        return $this->success([
            'items' => collect($paginator->items())->map($this->present(...))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function refund(Request $request, GatewayPayment $gatewayPayment): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:250']]);

        $payment = $this->payments->refund($gatewayPayment, $request->user(), $v['reason']);

        return $this->success($this->present($payment->load('user.profile')), 'Payment refunded');
    }

    /** @return array<string, mixed> */
    private function present(GatewayPayment $p): array
    {
        $user = $p->user;

        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'user' => $user ? [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.($user->last_name ?? '')),
                'phone' => $user->phone,
                'company_name' => $user->profile?->company_name,
            ] : null,
            'purpose' => $p->purpose->value,
            'amount_som' => intdiv((int) $p->amount_tiyin, 100),
            'status' => $p->status->value,
            'gateway' => $p->gateway,
            'is_card' => str_starts_with((string) $p->gateway_ref, 'card:') || str_starts_with((string) $p->gateway_ref, 'fake_card_'),
            'card_mask' => $p->meta['card_mask'] ?? null,
            'paid_at' => $p->paid_at,
            'created_at' => $p->created_at,
            'refund' => $p->meta['refund'] ?? null,
            'can_refund' => $p->status === GatewayPaymentStatus::Success,
        ];
    }
}
