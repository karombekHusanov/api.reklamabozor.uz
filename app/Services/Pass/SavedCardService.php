<?php

namespace App\Services\Pass;

use App\Contracts\CardTokenGateway;
use App\Contracts\PaymentGateway;
use App\Models\SavedCard;
use App\Models\User;
use App\Services\Payment\Gateway\BoundCard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/** The user's cards bound at the payment provider (token + masked PAN). */
class SavedCardService
{
    /** Cards usable with the current gateway, newest used first. */
    public function list(User $user): Collection
    {
        $gateway = $this->gatewayName();

        if ($gateway === null) {
            return new Collection;
        }

        return SavedCard::query()
            ->where('user_id', $user->id)
            ->where('gateway', $gateway)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get();
    }

    /** Re-binding the same card refreshes its token instead of duplicating it. */
    public function save(User $user, string $gateway, BoundCard $bound): SavedCard
    {
        return SavedCard::query()->updateOrCreate(
            ['user_id' => $user->id, 'gateway' => $gateway, 'card_id' => $bound->cardId],
            ['card_token' => $bound->cardToken, 'pan_mask' => $bound->panMask],
        );
    }

    public function findForUser(User $user, int $id): ?SavedCard
    {
        $gateway = $this->gatewayName();

        return $gateway === null ? null : SavedCard::query()
            ->whereKey($id)
            ->where('user_id', $user->id)
            ->where('gateway', $gateway)
            ->first();
    }

    /**
     * Forget the card. The token is revoked at the provider on a best-effort
     * basis — the local row goes regardless, so it can never be charged again.
     */
    public function delete(SavedCard $card): void
    {
        try {
            $gateway = app(PaymentGateway::class);

            if ($gateway instanceof CardTokenGateway && $gateway->name() === $card->gateway) {
                $gateway->removeCard($card->card_id, $card->card_token);
            }
        } catch (\Throwable $e) {
            Log::warning('gateway.card.remove_failed', ['saved_card' => $card->id, 'error' => $e->getMessage()]);
        }

        $card->delete();
    }

    private function gatewayName(): ?string
    {
        try {
            $gateway = app(PaymentGateway::class);
        } catch (\RuntimeException) {
            return null;
        }

        return $gateway instanceof CardTokenGateway ? $gateway->name() : null;
    }
}
