<?php

namespace App\Services\Fiscal;

use App\Models\Category;
use App\Models\MxikCode;
use App\Models\Offer;
use App\Models\Order;

/**
 * Fiscal (OFD) data for pricelist rows.
 *
 * Every receipt line needs an MXIK code, its packaging code and a VAT rate.
 * Agents do not type those in — a row inherits them from the catalogue entry
 * mapped to the order's category, and the values are then frozen on the row.
 */
class FiscalService
{
    /**
     * The catalogue entry a category's rows default to (null when the operator
     * has not mapped that category yet).
     */
    public function defaultFor(?Category $category): ?MxikCode
    {
        if ($category === null) {
            return null;
        }

        return $category->defaultMxikCode()->where('is_active', true)->first();
    }

    /**
     * Fiscal fields to stamp on a new pricelist row for this order.
     *
     * @return array{mxik_code: string|null, package_code: string|null, vat_rate: string|null}
     */
    public function fieldsForOrder(?Order $order): array
    {
        $order?->loadMissing('category');
        $code = $this->defaultFor($order?->category);

        return $code?->fiscalFields() ?? [
            'mxik_code' => null,
            'package_code' => null,
            'vat_rate' => null,
        ];
    }

    /**
     * Fiscal coverage of the service catalogue: which categories already have a
     * default classifier code and which still block OFD.
     *
     * @return array{covered: int, total: int, missing: list<array<string, mixed>>}
     */
    public function coverage(): array
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->with('defaultMxikCode')
            ->orderBy('sort_order')
            ->get();

        $missing = $categories
            ->filter(fn (Category $category) => $category->defaultMxikCode->isEmpty())
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name_uz' => $category->name_uz,
                'name_ru' => $category->name_ru,
                'type' => $category->type?->value,
            ])
            ->values()
            ->all();

        return [
            'total' => $categories->count(),
            'covered' => $categories->count() - count($missing),
            'missing' => $missing,
        ];
    }

    /**
     * Whether every row of the offer can be fiscalised.
     */
    public function offerIsFiscalReady(?Offer $offer): bool
    {
        if ($offer === null) {
            return false;
        }

        $items = $offer->relationLoaded('items') ? $offer->items : $offer->items()->get();

        return $items->isNotEmpty() && $items->every(fn ($item) => $item->hasFiscalData());
    }

    /**
     * The OFD lines Multicard expects for an offer, or null when the pricelist
     * is not fully coded — the caller then skips OFD instead of sending a
     * placeholder receipt.
     *
     * @return list<array<string, mixed>>|null
     */
    public function receiptLines(?Offer $offer): ?array
    {
        if (! $this->offerIsFiscalReady($offer)) {
            return null;
        }

        $items = $offer->relationLoaded('items') ? $offer->items : $offer->items()->get();

        return $items->map(function ($item): array {
            // Multicard takes money fields in tiyin.
            $unitPrice = (int) round(((float) $item->unit_price) * 100);
            $total = (int) round(((float) $item->lineTotal()) * 100);

            return [
                'name' => $item->name,
                'qty' => (float) $item->quantity,
                'price' => $unitPrice,
                'total' => $total,
                'mxik' => $item->mxik_code,
                'package_code' => $item->package_code,
                'vat' => (int) round(((float) ($item->vat_rate ?? 0)) * $total / 100),
            ];
        })->values()->all();
    }
}
