<?php

namespace App\Services\Payout;

use App\Enums\PayoutStatus;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Payment\MulticardClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drives an agent's on-demand cash-out to their card. The agent's available
 * (pending) payouts are reserved into one withdrawal, then released to their
 * card through Multicard's hosted bind form → `credit` → OTP flow:
 *
 *   start()   reserve payouts + open hosted card form   → card_pending
 *   advance() card bound → create gateway credit        → otp_required
 *   confirm() OTP verified → mark payouts paid           → success | failed
 *   cancel()  un-reserve payouts                          → cancelled
 *
 * Card data is entered only on Multicard's page; the one-time token is used
 * for the credit and then annulled — we never store a card.
 */
class WithdrawalService
{
    public function __construct(
        private readonly MulticardClient $client,
    ) {}

    /**
     * Reserve the agent's available payouts into a new withdrawal and open the
     * hosted card-entry form.
     */
    public function start(User $agent): Withdrawal
    {
        $pending = $agent->payouts()->where('status', PayoutStatus::Pending->value)->get();

        if ($pending->isEmpty()) {
            throw ValidationException::withMessages(['amount' => ['You have no funds available to withdraw.']]);
        }

        if (blank($agent->phone)) {
            throw ValidationException::withMessages(['phone' => ['A phone number is required to withdraw.']]);
        }

        $amount = (int) $pending->sum('amount');

        return DB::transaction(function () use ($agent, $pending, $amount): Withdrawal {
            $withdrawal = $agent->withdrawals()->create([
                'agent_profile_id' => $agent->primaryProviderProfile()?->id,
                'method' => 'card',
                'amount' => $amount,
                'currency' => 'UZS',
                'status' => WithdrawalStatus::Draft,
            ]);

            // Reserve the covered payouts so the balance can't be double-spent.
            $agent->payouts()
                ->whereIn('id', $pending->pluck('id'))
                ->update(['status' => PayoutStatus::Processing->value, 'withdrawal_id' => $withdrawal->id]);

            $form = $this->client->bindCardForm([
                'store_id' => (int) config('services.multicard.store_id'),
                'phone' => $this->normalizePhone($agent->phone),
                'callback_url' => (string) config('services.multicard.callback_url'),
                'redirect_url' => $this->miniAppReturnUrl(),
                'redirect_decline_url' => $this->miniAppReturnUrl(),
            ]);

            $withdrawal->update([
                'session_id' => $form['session_id'] ?? null,
                'form_url' => $form['form_url'] ?? null,
                'status' => WithdrawalStatus::CardPending,
            ]);

            return $withdrawal->refresh();
        });
    }

    /**
     * Advance a card_pending withdrawal: once the card is bound, create the
     * gateway credit. The card was already OTP-verified during binding, so we
     * request an OTP-less credit (`confirmable: false`) — it settles in one
     * shot and the user is NOT asked for a second code back in the app. If the
     * terminal still returns a draft, we fall back to the OTP step. No-op if the
     * card isn't bound yet or the withdrawal has moved on. Idempotent.
     *
     * @param  array{ip?: string|null, user_agent?: string|null}  $context
     */
    public function advance(Withdrawal $withdrawal, array $context = []): Withdrawal
    {
        if ($withdrawal->status !== WithdrawalStatus::CardPending || blank($withdrawal->session_id)) {
            return $withdrawal;
        }

        $binding = $this->client->getCardBinding((string) $withdrawal->session_id);

        $token = $binding['card_token'] ?? null;

        if (($binding['status'] ?? null) !== 'active' || ! is_string($token) || $token === '') {
            return $withdrawal; // card not entered yet
        }

        $payload = [
            'card' => ['token' => $token],
            'amount' => $withdrawal->amount,
            'store_id' => (string) config('services.multicard.store_id'),
            'invoice_id' => 'wd-'.$withdrawal->id,
            // Binding already proved card ownership via OTP — no second OTP.
            'confirmable' => false,
        ];

        if (! blank($context['ip'] ?? null) || ! blank($context['user_agent'] ?? null)) {
            $payload['device_details'] = array_filter([
                'ip' => $context['ip'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
            ]);
        }

        $credit = $this->client->createCardPayout($payload);
        $creditStatus = $credit['status'] ?? null;

        $withdrawal->update([
            'card_token' => $token,
            'card_pan' => $binding['card_pan'] ?? null,
            'ps' => $binding['ps'] ?? null,
            'gateway_uuid' => $credit['uuid'] ?? null,
            // Default to the OTP fallback; overwritten below on a decisive result.
            'status' => WithdrawalStatus::OtpRequired,
        ]);

        // OTP-less credit settles immediately — no second code in the app.
        if ($creditStatus === 'success') {
            $this->finalizeSuccess($withdrawal);
        } elseif ($creditStatus === 'error') {
            $withdrawal->update([
                'status' => WithdrawalStatus::Failed,
                'failure_reason' => 'credit_error',
            ]);
            $this->releaseReservation($withdrawal);
        }

        return $withdrawal->refresh();
    }

    /**
     * Confirm an otp_required withdrawal with the OTP the cardholder received.
     */
    public function confirm(Withdrawal $withdrawal, string $otp): Withdrawal
    {
        if ($withdrawal->status !== WithdrawalStatus::OtpRequired || blank($withdrawal->gateway_uuid)) {
            throw ValidationException::withMessages(['otp' => ['This withdrawal is not awaiting a code.']]);
        }

        $result = $this->client->confirmCardPayout((string) $withdrawal->gateway_uuid, $otp);

        if (($result['status'] ?? null) === 'success') {
            $this->finalizeSuccess($withdrawal);

            return $withdrawal->refresh();
        }

        $withdrawal->update([
            'status' => WithdrawalStatus::Failed,
            'failure_reason' => (string) ($result['status'] ?? 'error'),
        ]);

        $this->releaseReservation($withdrawal);

        return $withdrawal->refresh();
    }

    /**
     * Abandon an in-flight withdrawal and return its reserved payouts to the
     * available balance.
     */
    public function cancel(Withdrawal $withdrawal): Withdrawal
    {
        if ($withdrawal->status->isFinal()) {
            throw ValidationException::withMessages(['status' => ['This withdrawal can no longer be cancelled.']]);
        }

        $withdrawal->update(['status' => WithdrawalStatus::Cancelled]);
        $this->releaseReservation($withdrawal);

        return $withdrawal->refresh();
    }

    /**
     * Mark the withdrawal paid: its reserved payouts become paid and the
     * one-time card token is annulled so no card reference is kept.
     */
    private function finalizeSuccess(Withdrawal $withdrawal): void
    {
        DB::transaction(function () use ($withdrawal): void {
            $withdrawal->payouts()->update([
                'status' => PayoutStatus::Paid->value,
                'method' => 'card',
                'gateway_uuid' => $withdrawal->gateway_uuid,
                'paid_at' => now(),
            ]);

            $withdrawal->update([
                'status' => WithdrawalStatus::Success,
                'paid_at' => now(),
            ]);
        });

        if (! blank($withdrawal->card_token)) {
            $this->client->annulCardToken((string) $withdrawal->card_token);
        }

        // Drop the transient token from our store.
        $withdrawal->update(['card_token' => null]);
    }

    /** Return reserved (processing) payouts to the available (pending) balance. */
    private function releaseReservation(Withdrawal $withdrawal): void
    {
        $withdrawal->payouts()
            ->where('status', PayoutStatus::Processing->value)
            ->update(['status' => PayoutStatus::Pending->value, 'withdrawal_id' => null]);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return str_starts_with($digits, '998') ? $digits : '998'.$digits;
    }

    private function miniAppReturnUrl(): string
    {
        $base = trim((string) config('services.telegram.mini_app_url'));

        return $base !== '' ? rtrim($base, '/').'/profile' : 'https://t.me';
    }
}
