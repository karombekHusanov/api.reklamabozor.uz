<?php

namespace App\Services\Placement;

use App\Models\AdPlacement;
use App\Models\AdPlacementRate;

/**
 * The map calculator: price of a set of screens for one clip length over a
 * number of days, straight from the owners' rate cards.
 *
 * A rate is picked by exact clip length (owners sell 5/10/15/20 s, the price is
 * not linear in seconds) and pro-rated by days. Screens without a rate for the
 * chosen clip come back unpriced rather than guessed. Everything is an estimate
 * net of VAT; the VAT line is added on top.
 */
class PlacementEstimateService
{
    /**
     * @param  list<int>  $placementIds
     * @return array<string, mixed>
     */
    public function estimate(array $placementIds, int $days, int $slotSeconds): array
    {
        $placements = AdPlacement::query()
            ->bookable()
            ->whereIn('id', $placementIds)
            ->with('activeRates')
            ->get()
            ->keyBy('id');

        $items = [];
        $subtotal = 0;
        foreach ($placementIds as $id) {
            $placement = $placements->get($id);
            if ($placement === null) {
                continue; // hidden / removed since the client picked it
            }

            $rate = $this->rateFor($placement, $slotSeconds);
            $price = $rate?->priceForDaysTiyin($days);
            $subtotal += $price ?? 0;

            $items[] = [
                'placement_id' => $placement->id,
                'name' => $placement->displayName(),
                'size' => $placement->sizeLabel(),
                'address' => $placement->address,
                'owner_name' => $placement->owner_name_raw,
                'slot_options' => $placement->slotOptions(),
                'priced' => $rate !== null,
                'rate' => $rate === null ? null : [
                    'unit' => $rate->unit,
                    'slot_seconds' => $rate->slot_seconds,
                    'price_som' => intdiv($rate->price_tiyin, 100),
                ],
                'day_price_som' => $rate === null ? null : intdiv($rate->dayPriceTiyin(), 100),
                'price_som' => $price === null ? null : intdiv($price, 100),
            ];
        }

        $vatPercent = (int) config('placements.vat_percent', 12);
        $vat = (int) round($subtotal * $vatPercent / 100 / 100) * 100;

        return [
            'days' => $days,
            'slot_seconds' => $slotSeconds,
            'month_days' => (int) config('placements.month_days', 30),
            'min_days' => max(1, (int) config('placements.min_estimate_days', 30)),
            'items' => $items,
            'priced_count' => count(array_filter($items, fn (array $i) => $i['priced'])),
            'unpriced_count' => count(array_filter($items, fn (array $i) => ! $i['priced'])),
            'subtotal_som' => intdiv($subtotal, 100),
            'vat_percent' => $vatPercent,
            'vat_som' => intdiv($vat, 100),
            'total_som' => intdiv($subtotal + $vat, 100),
        ];
    }

    /** Cheapest-per-day active rate sold for exactly this clip length. */
    private function rateFor(AdPlacement $placement, int $slotSeconds): ?AdPlacementRate
    {
        return $placement->activeRates
            ->where('slot_seconds', $slotSeconds)
            ->sortBy(fn (AdPlacementRate $rate) => $rate->dayPriceTiyin())
            ->first();
    }
}
