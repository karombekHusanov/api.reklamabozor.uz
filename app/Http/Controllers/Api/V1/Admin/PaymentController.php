<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\AdminPaymentResource;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = Payment::query()->with('payer')->latest();

        if (($status = $request->query('status')) && PaymentStatus::tryFrom((string) $status)) {
            $query->where('status', $status);
        }

        if (($purpose = $request->query('purpose')) && PaymentPurpose::tryFrom((string) $purpose)) {
            $query->where('purpose', $purpose);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search): void {
                $q->where('payment_uuid', 'like', "%{$search}%")
                    ->orWhere('gateway_uuid', 'like', "%{$search}%")
                    ->orWhere('billing_id', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        return $this->success([
            'items' => AdminPaymentResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        return $this->success(new AdminPaymentResource($payment->load('payer')));
    }
}
