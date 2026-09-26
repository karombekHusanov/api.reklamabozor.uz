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
    public const VERSION = 'v2';

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
        $offer->loadMissing(['agentProfile', 'agent', 'order.client.legalEntityVerification', 'order.category', 'order.region']);
        $order = $offer->order;

        $items = $draftItems !== null
            ? $this->draftItemsSnapshot($draftItems)
            : $this->itemsSnapshot($offer);

        $total = $this->sumItems($items);
        $days = $deadlineDays ?? ($offer->deadline_days !== null ? (int) $offer->deadline_days : null);
        $deadlineLabel = $this->deadlineLabel($order, $days);

        $template = $this->template();

        $document = [
            'version' => self::VERSION,
            'terms_version' => (string) config('legal.terms_version'),
            'number' => $this->number($order),
            'title' => $template['title'],
            'subtitle' => $template['subtitle'],
            'city' => $template['city'],
            'draft_note' => $template['draft_note'],
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

        $document['intro'] = $this->intro($document, $template);
        $document['sections'] = $this->sections($document, $order, $template);
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
            ContractAcceptance::PARTY_AGENT => 'Ижрочи',
            ContractAcceptance::PARTY_CLIENT => 'Мижоз',
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
     * The approved contract text (resources/legal/order_contract.php).
     *
     * @return array<string, mixed>
     */
    private function template(): array
    {
        return require resource_path('legal/order_contract.php');
    }

    /**
     * Preamble naming the three parties, filled from their snapshots.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $template
     */
    private function intro(array $document, array $template): string
    {
        $client = $document['client'];
        $clientIsCompany = $client['is_legal_entity'] && $client['company_name'];

        $values = [
            '{operator_name}' => $template['operator']['name'],
            '{operator_director}' => $template['operator']['director'],
            '{client_name}' => ($clientIsCompany ? $client['company_name'] : $client['name']) ?: '—',
            '{client_id_label}' => $client['inn'] ? 'СТИР' : 'тел.',
            '{client_id}' => ($client['inn'] ?: $client['phone']) ?: '—',
            '{agent_name}' => $document['agent']['company_name'] ?: '—',
            '{agent_inn}' => $document['agent']['inn'] ?: '—',
        ];

        return implode("\n", array_map(
            fn (string $line): string => strtr($line, $values),
            $template['preamble'],
        ));
    }

    /**
     * Clause text. Single source for both the in-app drawer and the PDF.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $template
     * @return list<array<string, mixed>>
     */
    private function sections(array $document, ?Order $order, array $template): array
    {
        return array_map(function (array $section) use ($document, $order, $template): array {
            $built = [
                'key' => $section['key'],
                'heading' => $section['title'],
                'type' => $section['type'],
                'paragraphs' => array_map(
                    fn (array $clause): string => trim($clause['label'].' '.$clause['text']),
                    $section['clauses'],
                ),
            ];

            if ($section['type'] === 'items') {
                $built['rows'] = $this->detailRows($document, $order);
            }

            if ($section['type'] === 'parties') {
                $built['parties'] = $this->partyBlocks($document, $template);
            }

            return $built;
        }, $template['sections']);
    }

    /**
     * §2 "Буюртма тафсилотлари" — the order card the parties sign under. The
     * advance/final split follows §4.2 and §4.7: the advance is a share of the
     * order value, the final part is the rest minus the operator commission.
     *
     * @param  array<string, mixed>  $document
     * @return list<array{label: string, value: string}>
     */
    private function detailRows(array $document, ?Order $order): array
    {
        $money = fn (string $v): string => number_format((float) $v, 0, '.', ' ').' сўм';
        $advancePercent = (float) config('payments.advance_percent', 40);
        $commissionPercent = (float) config('payments.commission_percent', 7);
        $percent = fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

        $total = (string) $document['total'];
        $advance = bcdiv(bcmul($total, (string) $advancePercent, 4), '100', 2);
        $commission = bcdiv(bcmul($total, (string) $commissionPercent, 4), '100', 2);
        $final = bcsub(bcsub($total, $advance, 2), $commission, 2);

        $days = $document['deadline_days'];
        $term = $days !== null && $days > 0
            ? 'бошланиш: '.now()->format('d.m.Y').'  —  якунланиш: '.now()->addDays($days)->format('d.m.Y')
            : ($document['deadline_label'] ?: '—');

        $place = $order?->location_label ?: $order?->region?->name_uz;

        return [
            ['label' => 'Буюртма рақами', 'value' => '№ '.($order?->id ?? '—').'  /  сана: '.($order?->created_at?->format('d.m.Y') ?? now()->format('d.m.Y'))],
            ['label' => 'Хизмат тури', 'value' => ($order?->category?->name_uz ?: $order?->title) ?: '—'],
            ['label' => 'Тавсиф / кўлам', 'value' => $order?->description ?: '—'],
            ['label' => 'Жой (манзил)', 'value' => $place ?: '—'],
            ['label' => 'Бажарилиш муддати', 'value' => $term],
            ['label' => 'Буюртма қиймати', 'value' => $money($total)],
            ['label' => 'Аванс тўлови ('.$percent($advancePercent).'%)', 'value' => $money($advance)],
            ['label' => 'Якуний тўлов ('.$percent(100 - $advancePercent).'%)', 'value' => $money($final).' (Оператор комиссияси — '.$percent($commissionPercent).'% айирилган ҳолда)'],
        ];
    }

    /**
     * §11 requisites columns: Operator (fixed, from the approved text), Client,
     * Executor (from their snapshots). Empty rows are dropped.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $template
     * @return list<array{key: string, label: string, name: string, rows: list<array{label: string, value: string}>}>
     */
    private function partyBlocks(array $document, array $template): array
    {
        $rows = fn (array $pairs): array => array_values(array_map(
            fn (string $label, $value): array => ['label' => $label, 'value' => (string) $value],
            array_keys(array_filter($pairs, fn ($v) => $v !== null && $v !== '')),
            array_filter($pairs, fn ($v) => $v !== null && $v !== ''),
        ));

        $client = $document['client'];
        $clientIsCompany = $client['is_legal_entity'] && $client['company_name'];
        $agent = $document['agent'];

        return [
            [
                'key' => 'operator',
                'label' => 'ОПЕРАТОР',
                'name' => $template['operator']['name'],
                'rows' => $template['operator']['requisites'],
            ],
            [
                'key' => 'client',
                'label' => 'МИЖОЗ',
                'name' => ($clientIsCompany ? $client['company_name'] : $client['name']) ?: '—',
                'rows' => $rows([
                    'Вакил' => $clientIsCompany ? ($client['name'] ?: null) : null,
                    'Шахс' => $client['is_legal_entity'] ? 'юридик шахс' : 'жисмоний шахс',
                    'СТИР' => $client['inn'],
                    'Тел' => $client['phone'],
                ]),
            ],
            [
                'key' => 'agent',
                'label' => 'ИЖРОЧИ',
                'name' => $agent['company_name'] ?: '—',
                'rows' => $rows([
                    'Шакл' => $agent['legal_form'],
                    'Манзил' => $agent['address'],
                    'СТИР' => $agent['inn'],
                    'Ҳ/р' => $agent['bank_account'],
                    'Банк' => $agent['bank_name'],
                    'МФО' => $agent['mfo'],
                    'Раҳбар' => $agent['director_name'],
                    'Тел' => $agent['phone'],
                ]),
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
            'commission_percent' => (float) config('payments.commission_percent', 7),
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
