<?php

namespace App\Services\Pass;

use App\Enums\PassMode;
use App\Models\PlatformSetting;

/**
 * Admin-overridable Propusk knobs. A row in `platform_settings` wins over the
 * `config/passes.php` default. Values are wrapped as {"v": ...} so an explicit
 * null (e.g. "unlimited claims") is distinguishable from "not overridden".
 */
class PassSettings
{
    /** @var list<string> */
    public const KEYS = ['mode', 'price_som', 'hours', 'response_price_som', 'max_active_claims'];

    public function get(string $key): mixed
    {
        $row = PlatformSetting::query()->find("passes.$key");

        if ($row !== null && is_array($row->value) && array_key_exists('v', $row->value)) {
            return $row->value['v'];
        }

        return config("passes.$key");
    }

    public function mode(): PassMode
    {
        return PassMode::tryFrom((string) $this->get('mode')) ?? PassMode::DailyPass;
    }

    public function priceSom(): int
    {
        return (int) $this->get('price_som');
    }

    public function priceTiyin(): int
    {
        return $this->priceSom() * 100;
    }

    public function responsePriceTiyin(): int
    {
        return (int) $this->get('response_price_som') * 100;
    }

    public function hours(): int
    {
        return max(1, (int) $this->get('hours'));
    }

    public function maxActiveClaims(): ?int
    {
        $v = $this->get('max_active_claims');

        return $v === null || $v === '' ? null : (int) $v;
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
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return [
            'mode' => $this->mode()->value,
            'price_som' => $this->priceSom(),
            'hours' => $this->hours(),
            'response_price_som' => (int) $this->get('response_price_som'),
            'max_active_claims' => $this->maxActiveClaims(),
            'enforce' => (bool) config('passes.enforce'),
            'wallet_enabled' => (bool) config('passes.wallet_enabled'),
        ];
    }
}
