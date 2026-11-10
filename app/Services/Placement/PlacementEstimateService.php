<?php

namespace App\Services\Placement;

use App\Models\AdFormat;
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
 *
 * Metro formats ({@see AdFormat}) ride along: digital ones follow the same
 * clip × days rule times the number of units; static ones are billed in whole
 * months (never fewer than the format's minimum term) and ignore the clip.
 */
class PlacementEstimateService
{
    /**
     * @param  list<int>  $placementIds
     * @param  list<array{format_id: int, quantity: int}>  $formats
     * @return array<string, mixed>
     */
    public function estimate(array $placementIds, int $days, int $slotSeconds, array $formats = []): array
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

        $formatItems = $this->formatItems($formats, $days, $slotSeconds);
        $subtotal += array_sum(array_map(fn (array $i) => ($i['price_som'] ?? 0) * 100, $formatItems));
        $all = [...$items, ...$formatItems];

        $vatPercent = (int) config('placements.vat_percent', 12);
        $vat = (int) round($subtotal * $vatPercent / 100 / 100) * 100;

        return [
            'days' => $days,
            'slot_seconds' => $slotSeconds,
            'month_days' => (int) config('placements.month_days', 30),
            'min_days' => max(1, (int) config('placements.min_estimate_days', 30)),
            'items' => $items,
            'format_items' => $formatItems,
            'priced_count' => count(array_filter($all, fn (array $i) => $i['priced'])),
            'unpriced_count' => count(array_filter($all, fn (array $i) => ! $i['priced'])),
            'subtotal_som' => intdiv($subtotal, 100),
            'vat_percent' => $vatPercent,
            'vat_som' => intdiv($vat, 100),
            'total_som' => intdiv($subtotal + $vat, 100),
        ];
    }

    /**
     * @param  list<array{format_id: int, quantity: int}>  $rows
     * @return list<array<string, mixed>>
     */
    private function formatItems(array $rows, int $days, int $slotSeconds): array
    {
        if ($rows === []) {
            return [];
        }

        $monthDays = max(1, (int) config('placements.month_days', 30));
        $formats = AdFormat::query()->active()->whereIn('id', array_column($rows, 'format_id'))->get()->keyBy('id');

        $items = [];
        foreach ($rows as $row) {
            $format = $formats->get($row['format_id']);
            if ($format === null) {
                continue;
            }

            // A package is the whole network — there is nothing to multiply.
            $quantity = $format->is_package ? 1 : min($row['quantity'], $format->total_count);
            $monthPrice = $format->monthPriceTiyin($slotSeconds);
            // Static print is rented by the whole month, never below the minimum
            // term; digital formats also respect a minimum term when one is set.
            $billedDays = $format->isStatic()
                ? max($format->min_months, (int) ceil($days / $monthDays)) * $monthDays
                : max($days, $format->min_months * $monthDays);
            $price = $monthPrice === null
                ? null
                : (int) round($monthPrice * $billedDays / $monthDays / 100) * 100 * $quantity;

            $items[] = [
                'format_id' => $format->id,
                'code' => $format->code,
                'medium' => $format->medium,
                'name_uz' => $format->name_uz,
                'name_ru' => $format->name_ru,
                'owner_name' => $format->owner_name,
                'is_package' => $format->is_package,
                'quantity' => $quantity,
                'total_count' => $format->total_count,
                'slot_options' => $format->slotOptions(),
                'priced' => $monthPrice !== null,
                'month_price_som' => $monthPrice === null ? null : intdiv($monthPrice, 100),
                'billed_days' => $billedDays,
                // The term was stretched to the format's minimum / a whole month.
                'term_adjusted' => $billedDays !== $days,
                'production_included' => $format->production_included,
                'price_som' => $price === null ? null : intdiv($price, 100),
            ];
        }

        return $items;
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
