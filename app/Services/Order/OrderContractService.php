<?php

namespace App\Services\Order;

use App\Enums\LegalEntityStatus;
use App\Enums\PersonType;
use App\Models\Contract;
use App\Models\ContractAcceptance;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\File\FileService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Builds the per-order service contract between the client (Buyurtmachi), the
 * agent (Ijrochi) and the platform as payment operator (Operator).
 *
 * Two moments matter:
 *  - {@see document()} renders the contract the parties read and accept in-app
 *    (agent when sending the pricelist, client when accepting the offer); each
 *    acceptance is logged by {@see recordAcceptance()}.
 *  - {@see generateForOrder()} freezes the same document into an immutable PDF
 *    once the deal is active — proof, not the binding moment.
 */
class OrderContractService
{
    /** Bump when the contract template/terms change. */
    public const VERSION = 'v1';

    public function __construct(private readonly FileService $files) {}

    /**
     * Generate (idempotently) the contract for an order whose deal has started.
     * Returns the existing contract if one was already generated.
     */
    public function generateForOrder(Order $order): ?Contract
    {
        $existing = $order->contract()->first();
        if ($existing !== null) {
            return $existing;
        }

        /** @var Offer|null $offer */
        $offer = $order->acceptedOffer()->with(['items', 'agentProfile', 'agent'])->first();
        if ($offer === null) {
            return null;
        }

        $document = $this->document($offer);

        $pdf = Pdf::loadView('contracts.order_agreement', [
            'doc' => $document,
            'acceptances' => $this->acceptanceLog($offer),
        ])->setPaper('a4');

        $contents = $pdf->output();

        $file = $this->files->storeContents(
            $contents,
            "shartnoma-order-{$order->id}.pdf",
            'application/pdf',
            $order->client_id,
            'contracts',
        );

        return Contract::create([
            'order_id' => $order->id,
            'offer_id' => $offer->id,
            'number' => $document['number'],
            'total' => $document['total'],
            'client_snapshot' => $document['client'],
            'agent_snapshot' => $document['agent'],
            'items_snapshot' => $document['items'],
            'pdf_file_id' => $file->id,
            'hash' => hash('sha256', $contents),
            'version' => self::VERSION,
            'generated_at' => now(),
        ]);
    }

    /**
     * The contract as the parties see it before accepting: requisites of all
     * three parties, the pricelist, and the clause text.
     *
     * @param  list<array{name: string, unit?: string|null, quantity: float|int|string, unit_price: float|int|string}>|null  $draftItems
     *                                                                                                                                    Pricelist rows not yet stored — the agent previews the contract
     *                                                                                                                                    built from the lines they are about to send.
     * @return array<string, mixed>
     */
    public function document(Offer $offer, ?array $draftItems = null, ?int $deadlineDays = null): array
    {
        $offer->loadMissing(['agentProfile', 'agent', 'order.client.legalEntityVerification', 'order.category']);
        $order = $offer->order;

        $items = $draftItems !== null
            ? $this->draftItemsSnapshot($draftItems)
            : $this->itemsSnapshot($offer);

        $total = $this->sumItems($items);
        $days = $deadlineDays ?? ($offer->deadline_days !== null ? (int) $offer->deadline_days : null);
        $deadlineLabel = $this->deadlineLabel($order, $days);

        $document = [
            'version' => self::VERSION,
            'terms_version' => (string) config('legal.terms_version'),
            'number' => $this->number($order),
            'title' => "Xizmat ko'rsatish shartnomasi (uch tomonlama)",
            'subtitle' => 'Buyurtmachi ↔ Ijrochi ↔ Operator («Reklama Bozor»)',
            'order_id' => $order?->id,
            'offer_id' => $offer->id,
            'agent' => $this->agentSnapshot($offer),
            'client' => $this->clientSnapshot($order),
            'platform' => $this->platformSnapshot(),
            'items' => $items,
            'total' => $total,
            'deadline_days' => $days,
            'deadline_label' => $deadlineLabel,
            'generated_at' => now()->toIso8601String(),
        ];

        $document['intro'] = $this->intro($document);
        $document['sections'] = $this->sections($document, $order);
        $document['hash'] = $this->hash($document);

        return $document;
    }

    /**
     * Log a party's click-wrap acceptance of the contract they were shown.
     * Append-only: a revised pricelist is a new document and a new acceptance.
     *
     * @param  array<string, mixed>  $document
     */
    public function recordAcceptance(
        Offer $offer,
        User $user,
        string $party,
        array $document,
        ?Request $request = null,
    ): ContractAcceptance {
        return ContractAcceptance::create([
            'order_id' => $offer->order_id,
            'offer_id' => $offer->id,
            'user_id' => $user->id,
            'party' => $party,
            'version' => $document['version'] ?? self::VERSION,
            'terms_version' => $document['terms_version'] ?? config('legal.terms_version'),
            'total' => $document['total'] ?? 0,
            'snapshot' => $document,
            'hash' => $document['hash'] ?? $this->hash($document),
            'accepted_at' => now(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    /**
     * Latest acceptance per party for an offer, keyed by party.
     *
     * @return array<string, ContractAcceptance>
     */
    public function acceptancesFor(Offer $offer): array
    {
        return ContractAcceptance::query()
            ->where('offer_id', $offer->id)
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('party')
            ->map(fn ($rows) => $rows->first())
            ->all();
    }

    /**
     * Printable "accepted by" lines for the PDF footer.
     *
     * @return list<array{label: string, name: string, accepted_at: string}>
     */
    private function acceptanceLog(Offer $offer): array
    {
        $labels = [
            ContractAcceptance::PARTY_AGENT => 'Ijrochi',
            ContractAcceptance::PARTY_CLIENT => 'Buyurtmachi',
        ];

        $log = [];

        foreach ($this->acceptancesFor($offer) as $party => $acceptance) {
            $acceptance->loadMissing('user');
            $name = trim(($acceptance->user?->first_name ?? '').' '.($acceptance->user?->last_name ?? ''));

            $log[] = [
                'label' => $labels[$party] ?? $party,
                'name' => $name !== '' ? $name : '—',
                'accepted_at' => $acceptance->accepted_at?->format('d.m.Y H:i') ?? '',
            ];
        }

        return $log;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function intro(array $document): string
    {
        $agent = $document['agent']['company_name'] ?: '—';
        $client = $document['client']['is_legal_entity'] && $document['client']['company_name']
            ? $document['client']['company_name']
            : ($document['client']['name'] ?: '—');
        $platform = $document['platform']['legal_name'] ?: $document['platform']['name'];

        return "Bir tomondan Ijrochi — {$agent}, ikkinchi tomondan Buyurtmachi — {$client}, "
            ."uchinchi tomondan Operator — {$platform}, birgalikda Tomonlar deb atalib, ushbu "
            .'shartnomani tuzdilar. Shartnoma Tomonlar tomonidan «Reklama Bozor» ilovasida '
            .'elektron shaklda (aksept tugmasi orqali) tasdiqlanadi va imzolangan hisoblanadi.';
    }

    /**
     * Clause text. Single source for both the in-app drawer and the PDF.
     *
     * @param  array<string, mixed>  $document
     * @return list<array{key: string, heading: string, type: string, paragraphs: list<string>}>
     */
    private function sections(array $document, ?Order $order): array
    {
        $money = fn ($v) => number_format((float) $v, 0, '.', ' ')." so'm";
        $commission = (float) config('services.multicard.commission_percent', 7);

        $subject = ['1.1. Ijrochi Buyurtmachiga quyida ko\'rsatilgan reklama/poligrafiya xizmatlarini '
            ."ko'rsatadi, Buyurtmachi esa ularni qabul qilib, kelishilgan narxni to'laydi."];

        if ($order?->description) {
            $subject[] = '1.2. Buyurtma tavsifi: '.$order->description;
        }

        $payment = [
            "3.1. Xizmatlar umumiy qiymati: {$money($document['total'])}.",
            "3.2. To'lov Operator (platforma) orqali amalga oshiriladi. Operator to'lovni qabul "
                .'qiladi, komissiyani ushlab qoladi va qolgan summani Ijrochiga o\'tkazadi.',
        ];

        if ($document['deadline_label']) {
            $payment[] = "3.3. Bajarilish muddati: {$document['deadline_label']}.";
        }

        return [
            [
                'key' => 'subject',
                'heading' => '1. Shartnoma predmeti',
                'type' => 'text',
                'paragraphs' => $subject,
            ],
            [
                'key' => 'services',
                'heading' => '2. Xizmatlar va narxi',
                'type' => 'items',
                'paragraphs' => [],
            ],
            [
                'key' => 'payment',
                'heading' => "3. To'lov va muddat",
                'type' => 'text',
                'paragraphs' => $payment,
            ],
            [
                'key' => 'operator',
                'heading' => '4. Operatorning roli',
                'type' => 'text',
                'paragraphs' => [
                    '4.1. Operator — marketplace va to\'lov operatori. Reklama xizmatini Operator '
                        .'ko\'rsatmaydi; xizmat sifati va muddati uchun javobgarlik Ijrochi zimmasida.',
                    "4.2. Operator xizmat qiymatidan {$commission}% miqdorida komissiya ushlab qoladi.",
                    '4.3. Operator Tomonlar o\'rtasidagi nizoda hakamlik qiladi va shartnoma '
                        .'shartlariga muvofiq to\'lovni chiqarish yoki qaytarish to\'g\'risida qaror qabul qiladi.',
                ],
            ],
            [
                'key' => 'liability',
                'heading' => '5. Tomonlarning javobgarligi',
                'type' => 'text',
                'paragraphs' => [
                    "5.1. Ijrochi xizmatlarni sifatli va o'z vaqtida bajarish uchun javobgardir.",
                    "5.2. Buyurtmachi qabul qilingan xizmatlar uchun to'lovni o'z vaqtida amalga oshiradi.",
                    '5.3. Reklama mazmuni (matn, tasvir) qonunchilikka muvofiqligi uchun javobgarlik '
                        .'Buyurtmachi zimmasida.',
                ],
            ],
            [
                'key' => 'disputes',
                'heading' => '6. Nizolarni hal qilish va amal qilish muddati',
                'type' => 'text',
                'paragraphs' => [
                    '6.1. Nizolar avval muzokara yo\'li bilan, Operator ishtirokida hal qilinadi.',
                    '6.2. Shartnoma Tomonlar aksepti (ilovadagi tasdiqlash) daqiqasidan kuchga kiradi '
                        .'va majburiyatlar to\'liq bajarilgunga qadar amal qiladi.',
                    '6.3. Elektron aksept va ilovada saqlanadigan tasdiqlash yozuvi Tomonlar uchun '
                        .'yozma shaklga tenglashtiriladi.',
                ],
            ],
            [
                'key' => 'requisites',
                'heading' => '7. Tomonlarning rekvizitlari',
                'type' => 'parties',
                'paragraphs' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function agentSnapshot(Offer $offer): array
    {
        $profile = $offer->agentProfile;
        $agent = $offer->agent;

        return [
            'company_name' => $profile?->company_name
                ?: trim(($agent?->first_name ?? '').' '.($agent?->last_name ?? '')),
            'legal_form' => $profile?->legal_form,
            'director_name' => $profile?->director_name,
            'inn' => $profile?->inn,
            'bank_name' => $profile?->bank_name,
            'bank_account' => $profile?->bank_account,
            'mfo' => $profile?->mfo,
            'phone' => $profile?->phone ?? $agent?->phone,
            'address' => $profile?->location_label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientSnapshot(?Order $order): array
    {
        $client = $order?->client;
        $isLegal = $client?->effectivePersonType() === PersonType::LegalEntity;
        $verification = $client?->legalEntityVerification;
        $verified = $verification?->status === LegalEntityStatus::Approved;

        return [
            'name' => trim(($client?->first_name ?? '').' '.($client?->last_name ?? '')),
            'phone' => $client?->phone,
            'person_type' => $client?->effectivePersonType()?->value,
            'is_legal_entity' => $isLegal,
            // Legal requisites only when the client is a verified legal entity.
            'company_name' => $verified ? $verification?->company_name : null,
            'inn' => $verified ? $verification?->inn : null,
        ];
    }

    /**
     * The platform as the contract's third party (operator only).
     *
     * @return array<string, mixed>
     */
    private function platformSnapshot(): array
    {
        /** @var array<string, mixed> $platform */
        $platform = config('legal.platform', []);

        return [
            'name' => $platform['name'] ?? '«Reklama Bozor» platformasi',
            'legal_name' => $platform['legal_name'] ?? null,
            'inn' => $platform['inn'] ?? null,
            'address' => $platform['address'] ?? null,
            'phone' => $platform['phone'] ?? null,
            'email' => $platform['email'] ?? null,
            'website' => $platform['website'] ?? null,
            'role' => "Marketplace va to'lov operatori",
            'commission_percent' => (float) config('services.multicard.commission_percent', 7),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemsSnapshot(Offer $offer): array
    {
        $items = $offer->relationLoaded('items') ? $offer->items : $offer->items()->get();

        if ($items->isEmpty()) {
            // Legacy single-price offer — represent it as one line.
            return [[
                'name' => $offer->comment ?: 'Xizmat',
                'unit' => 'dona',
                'quantity' => '1',
                'unit_price' => (string) ($offer->price ?? '0'),
                'line_total' => (string) ($offer->price ?? '0'),
            ]];
        }

        return $items->map(fn ($item) => [
            'name' => $item->name,
            'unit' => $item->unit,
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'line_total' => $item->lineTotal(),
        ])->all();
    }

    /**
     * Pricelist rows the agent is about to send (not persisted yet).
     *
     * @param  list<array{name: string, unit?: string|null, quantity: float|int|string, unit_price: float|int|string}>  $items
     * @return list<array<string, mixed>>
     */
    private function draftItemsSnapshot(array $items): array
    {
        return array_values(array_map(function (array $item): array {
            $unit = isset($item['unit']) && trim((string) $item['unit']) !== ''
                ? trim((string) $item['unit'])
                : 'dona';

            return [
                'name' => trim((string) $item['name']),
                'unit' => $unit,
                'quantity' => (string) $item['quantity'],
                'unit_price' => (string) $item['unit_price'],
                'line_total' => bcmul((string) $item['quantity'], (string) $item['unit_price'], 2),
            ];
        }, $items));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function sumItems(array $items): string
    {
        return array_reduce(
            $items,
            fn (string $carry, array $item): string => bcadd($carry, (string) $item['line_total'], 2),
            '0',
        );
    }

    /**
     * Stable fingerprint of the accepted text — volatile fields excluded so the
     * same document hashes identically for both parties.
     *
     * @param  array<string, mixed>  $document
     */
    private function hash(array $document): string
    {
        $core = [
            'version' => $document['version'] ?? self::VERSION,
            'number' => $document['number'] ?? null,
            'agent' => $document['agent'] ?? [],
            'client' => $document['client'] ?? [],
            'platform' => $document['platform'] ?? [],
            'items' => $document['items'] ?? [],
            'total' => $document['total'] ?? '0',
            'deadline_label' => $document['deadline_label'] ?? null,
            'intro' => $document['intro'] ?? null,
            'sections' => $document['sections'] ?? [],
        ];

        return hash('sha256', (string) json_encode($core, JSON_UNESCAPED_UNICODE));
    }

    private function number(?Order $order): string
    {
        return 'RB-'.($order?->id ?? 0).'-'.now()->format('Y');
    }

    /**
     * The agent's committed delivery deadline takes precedence; falls back to
     * the client's urgency preference on the order.
     */
    private function deadlineLabel(?Order $order, ?int $days): ?string
    {
        if ($days !== null && $days > 0) {
            $due = now()->addDays($days)->format('d.m.Y');

            return "{$days} kun ({$due} gacha)";
        }

        return match ($order?->deadline?->value) {
            'today_tomorrow' => 'Bugun-erta',
            'this_week' => 'Shu hafta',
            default => null,
        };
    }
}
