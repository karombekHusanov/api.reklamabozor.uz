<?php

namespace App\Services\Pass;

use App\Enums\TezkorBilling;
use App\Models\Order;
use App\Models\PlatformSetting;

/**
 * Admin-overridable per-order fee knobs. A row in `platform_settings` wins over
 * the `config/passes.php` default. Values are wrapped as {"v": ...} so an
 * explicit null is distinguishable from "not overridden".
 */
class PassSettings
{
    /** @var list<string> */
    public const KEYS = ['response_price_som', 'tezkor_billing', 'pick_pay_minutes'];

    /** @var array<string, mixed>|null overrides read once per instance (scoped per request) */
    private ?array $overrides = null;

    public function get(string $key): mixed
    {
        $this->overrides ??= PlatformSetting::query()
            ->whereIn('key', array_map(fn (string $k) => "passes.$k", self::KEYS))
            ->get()
            ->filter(fn (PlatformSetting $row) => is_array($row->value) && array_key_exists('v', $row->value))
            ->mapWithKeys(fn (PlatformSetting $row) => [substr($row->key, strlen('passes.')) => $row->value['v']])
            ->all();

        return array_key_exists($key, $this->overrides) ? $this->overrides[$key] : config("passes.$key");
    }

    public function responsePriceSom(): int
    {
        return (int) $this->get('response_price_som');
    }

    /**
     * The fee for this order — the same for Tender and both Tezkor modes for
     * now. The single seam for pricing by order size later.
     */
    public function feeTiyinFor(Order $order): int
    {
        return $this->responsePriceSom() * 100;
    }

    /** Billing frozen onto new Tezkor requests. */
    public function tezkorBilling(): TezkorBilling
    {
        return TezkorBilling::tryFrom((string) $this->get('tezkor_billing')) ?? TezkorBilling::PayToRespond;
    }

    public function pickPayMinutes(): int
    {
        return max(1, (int) $this->get('pick_pay_minutes'));
    }

    /**
     * @param  array<string, mixed>  $values  only keys present are written
     */
    public function update(array $values): void
    {
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $values)) {
                PlatformSetting::query()->updateOrCreate(
                    ['key' => "passes.$key"],
                    ['value' => ['v' => $values[$key]]],
                );
            }
        }

        $this->overrides = null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return [
            'response_price_som' => $this->responsePriceSom(),
            'tezkor_billing' => $this->tezkorBilling()->value,
            'pick_pay_minutes' => $this->pickPayMinutes(),
            'enforce' => (bool) config('passes.enforce'),
            'wallet_enabled' => (bool) config('passes.wallet_enabled'),
        ];
    }
}
