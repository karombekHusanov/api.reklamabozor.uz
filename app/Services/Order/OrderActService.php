<?php

namespace App\Services\Order;

use App\Enums\OrderDocumentType;
use App\Enums\OrderStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Services\File\FileService;
use App\Support\MoneyInWords;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The accounting documents that close an order, generated when it completes:
 *
 *  - the act of completed work (client ↔ agent), which the parties' books need
 *    to recognise the service and the expense;
 *  - the commission act (platform ↔ agent) for the intermediary fee withheld
 *    from the payout, which is the platform's own revenue document.
 *
 * Both are immutable: the snapshot freezes the requisites and the lines, and
 * the PDF is only its rendering.
 */
class OrderActService
{
    /** Bump when a template changes. */
    public const VERSION = 'v1';

    public function __construct(
        private readonly OrderContractService $contracts,
        private readonly FileService $files,
    ) {}

    /**
     * Generate everything a completed order owes the bookkeeping. Safe to call
     * repeatedly — existing documents are returned untouched.
     *
     * @return array<string, OrderDocument>
     */
    public function generateForOrder(Order $order): array
    {
        $documents = [];

        foreach (OrderDocumentType::cases() as $type) {
            $document = $this->documentFor($order, $type);

            if ($document !== null) {
                $documents[$type->value] = $document;
            }
        }

        return $documents;
    }

    /**
     * The stored document, generating it on first request. Only a completed
     * order has acts — before that there is nothing to certify.
     */
    public function documentFor(Order $order, OrderDocumentType $type): ?OrderDocument
    {
        $existing = $order->documents()->where('type', $type->value)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($order->status !== OrderStatus::Completed) {
            return null;
        }

        /** @var Offer|null $offer */
        $offer = $order->acceptedOffer()->with(['items', 'agentProfile', 'agent'])->first();

        if ($offer === null) {
            return null;
        }

        $snapshot = $type === OrderDocumentType::WorkAct
            ? $this->workActSnapshot($order, $offer)
            : $this->commissionActSnapshot($order, $offer);

        $view = $type === OrderDocumentType::WorkAct
            ? 'acts.work_act'
            : 'acts.commission_act';

        $contents = Pdf::loadView($view, ['doc' => $snapshot])->setPaper('a4')->output();

        $file = $this->files->storeContents(
            $contents,
            "{$type->numberSuffix()}-{$order->id}.pdf",
            'application/pdf',
            $order->client_id,
            'acts',
        );

        return OrderDocument::create([
            'order_id' => $order->id,
            'offer_id' => $offer->id,
            'type' => $type->value,
            'number' => $snapshot['number'],
            'total' => $snapshot['total'],
            'snapshot' => $snapshot,
            'pdf_file_id' => $file->id,
            'hash' => hash('sha256', $contents),
            'version' => self::VERSION,
            'generated_at' => now(),
        ]);
    }

    /**
     * Act of completed work: what was delivered, at what price, under which
     * contract, and that the client accepted it.
     *
     * @return array<string, mixed>
     */
    private function workActSnapshot(Order $order, Offer $offer): array
    {
        $contract = $this->contracts->document($offer);
        $items = $this->items($offer);
        $total = array_sum(array_map(static fn (array $i): float => (float) $i['line_total'], $items));

        return [
            'version' => self::VERSION,
            'type' => OrderDocumentType::WorkAct->value,
            'title' => 'BAJARILGAN ISHLAR DALOLATNOMASI',
            'subtitle' => 'Buyurtmachi ↔ Ijrochi',
            'number' => $this->number($order, OrderDocumentType::WorkAct),
            'date' => ($order->completed_at ?? now())->toIso8601String(),
            'order_id' => $order->id,
            'order_title' => $order->title,
            'contract' => [
                'number' => $order->contract?->number ?? $contract['number'],
                'date' => ($order->contract?->generated_at ?? $order->activated_at)?->toIso8601String(),
            ],
            'period' => [
                'from' => $order->activated_at?->toIso8601String(),
                'to' => ($order->completed_at ?? now())->toIso8601String(),
            ],
            'executor' => $contract['agent'],
            'customer' => $contract['client'],
            'operator' => $contract['platform'],
            'items' => $items,
            'total' => $total,
            'total_in_words' => MoneyInWords::som($total),
            'vat_note' => $this->vatNote($items),
            'clauses' => [
                'Ijrochi shartnoma bo\'yicha xizmatlarni to\'liq bajardi, Buyurtmachi ularni qabul qildi.',
                'Tomonlarning bajarilgan ishlar hajmi, sifati va muddatlari bo\'yicha bir-biriga da\'vosi yo\'q.',
                'Ushbu dalolatnoma yuqoridagi shartnomaning ajralmas qismi hisoblanadi va ikki nusxada tuziladi.',
                'To\'lov Operator («Reklama Bozor») orqali amalga oshiriladi; Operator to\'lov agenti sifatida ishtirok etadi.',
            ],
        ];
    }

    /**
     * Commission act: the fee the platform withheld from the agent's payout.
     *
     * @return array<string, mixed>
     */
    private function commissionActSnapshot(Order $order, Offer $offer): array
    {
        $contract = $this->contracts->document($offer);

        $dealTotal = (float) ($offer->price ?? 0);
        $percent = (float) config('payments.commission_percent', 7);
        $commission = round($dealTotal * $percent / 100, 2);

        return [
            'version' => self::VERSION,
            'type' => OrderDocumentType::CommissionAct->value,
            'title' => 'VOSITACHILIK XIZMATI DALOLATNOMASI',
            'subtitle' => 'Operator ↔ Ijrochi (agentlik)',
            'number' => $this->number($order, OrderDocumentType::CommissionAct),
            'date' => ($order->completed_at ?? now())->toIso8601String(),
            'order_id' => $order->id,
            'order_title' => $order->title,
            'contract' => [
                'number' => $order->contract?->number ?? $contract['number'],
                'date' => ($order->contract?->generated_at ?? $order->activated_at)?->toIso8601String(),
            ],
            // The platform's own bank requisites are not part of the contract
            // snapshot (it must stay byte-stable), but an act needs them.
            'provider' => array_merge($contract['platform'], [
                'bank_name' => config('legal.platform.bank_name'),
                'bank_account' => config('legal.platform.bank_account'),
                'mfo' => config('legal.platform.mfo'),
            ]),
            'payer' => $contract['agent'],
            'deal_total' => $dealTotal,
            'commission_percent' => $percent,
            'total' => $commission,
            'total_in_words' => MoneyInWords::som($commission),
            'net_amount' => round($dealTotal - $commission, 2),
            'clauses' => [
                'Operator Ijrochiga marketplace orqali buyurtma topish, buyurtmachi bilan hujjat almashinuvi va to\'lovlarni qabul qilish xizmatini ko\'rsatdi.',
                'Xizmat haqi shartnoma summasidan foizda hisoblanadi va Ijrochiga to\'lanadigan summadan ushlab qolinadi.',
                'Tomonlarning ko\'rsatilgan xizmat hajmi va sifati bo\'yicha bir-biriga da\'vosi yo\'q.',
            ],
        ];
    }

    /**
     * Pricelist lines with their fiscal codes — an act without them cannot back
     * a receipt.
     *
     * @return list<array<string, mixed>>
     */
    private function items(Offer $offer): array
    {
        $items = $offer->relationLoaded('items') ? $offer->items : $offer->items()->get();

        if ($items->isEmpty()) {
            return [[
                'name' => $offer->comment ?: 'Xizmat',
                'unit' => 'dona',
                'quantity' => '1',
                'unit_price' => (string) ($offer->price ?? '0'),
                'line_total' => (string) ($offer->price ?? '0'),
                'mxik_code' => null,
                'package_code' => null,
                'vat_rate' => null,
            ]];
        }

        return $items->map(fn ($item): array => [
            'name' => $item->name,
            'unit' => $item->unit,
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'line_total' => $item->lineTotal(),
            'mxik_code' => $item->mxik_code,
            'package_code' => $item->package_code,
            'vat_rate' => $item->vat_rate,
        ])->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function vatNote(array $items): string
    {
        $rates = array_filter(array_map(static fn (array $i) => $i['vat_rate'], $items), static fn ($r) => $r !== null);

        if ($rates === []) {
            return 'QQS solinmaydi (soliq to\'g\'risidagi qonunchilikka muvofiq).';
        }

        $unique = array_unique(array_map(static fn ($r): string => rtrim(rtrim((string) $r, '0'), '.'), $rates));

        return 'QQS stavkasi: '.implode(', ', $unique).'%.';
    }

    private function number(Order $order, OrderDocumentType $type): string
    {
        $base = $order->contract?->number ?? 'RB-'.$order->id.'-'.($order->activated_at ?? $order->created_at ?? now())->year;

        return $base.'/'.$type->numberSuffix();
    }
}
