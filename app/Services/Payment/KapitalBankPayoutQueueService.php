<?php

namespace App\Services\Payment;

use App\Models\Payout;
use Illuminate\Support\Facades\Log;

/**
 * Pushes releasable agent payouts to Kapitalbank as unsigned SendPaymentIBK
 * orders — "input only" per the bank's guidance. A human still signs and
 * sends each order from the Kapitalbank website (ECP + OTP, bulk-confirm
 * supported); this never marks a payout `paid` by itself. A manager still
 * does that in the admin panel (same as the fully manual flow) once the
 * bank-side transfer has actually gone through.
 */
class KapitalBankPayoutQueueService
{
    public function __construct(
        private readonly KapitalBankClient $client,
    ) {}

    public function queuePending(): void
    {
        $payouts = Payout::query()
            ->releasable()
            ->whereNull('bank_queue_status')
            ->with(['order.contract', 'agentProfile'])
            ->get();

        foreach ($payouts as $payout) {
            $this->queueOne($payout);
        }
    }

    private function queueOne(Payout $payout): void
    {
        $profile = $payout->agentProfile;

        if ($profile === null || ! $profile->hasBankRequisites() || blank($profile->inn)) {
            // Left unqueued (not marked failed) — the manual admin release path
            // already blocks on this same guard, so it's a normal, recoverable
            // "not ready yet" state, not an integration error.
            return;
        }

        $document = [
            'uniq' => 'RBPAYOUT-'.$payout->id,
            'mfo_ct' => (string) $profile->mfo,
            'acc_ct' => (string) $profile->bank_account,
            'name_ct' => (string) $profile->company_name,
            'inn_ct' => (string) $profile->inn,
            // Docs state monetary fields are in the smallest currency unit
            // (tiyin) — same convention as Payout::$amount, so no conversion.
            'amount' => $payout->amount,
            'purpose' => $this->purposeFor($payout),
            'ddate' => now()->format('d.m.Y'),
        ];

        try {
            $this->client->sendPaymentIBK($document);

            $payout->forceFill([
                'bank_uniq' => $document['uniq'],
                'bank_queue_status' => 'queued',
                'bank_queued_at' => now(),
                'bank_queue_error' => null,
            ])->save();

            Log::info('kapitalbank.payout_queue.queued', [
                'payout_id' => $payout->id,
                'amount' => $payout->amount,
            ]);
        } catch (\Throwable $e) {
            $payout->forceFill([
                'bank_uniq' => $document['uniq'],
                'bank_queue_status' => 'failed',
                'bank_queue_error' => $e->getMessage(),
            ])->save();

            Log::error('kapitalbank.payout_queue.failed', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function purposeFor(Payout $payout): string
    {
        $order = $payout->order;
        $contractNumber = $order?->contract?->number;

        return $contractNumber !== null
            ? "Shartnoma {$contractNumber} bo'yicha agent to'lovi ({$payout->tranche->value})"
            : "Buyurtma #{$payout->order_id} bo'yicha agent to'lovi ({$payout->tranche->value})";
    }
}
