<?php

namespace App\Services\Order;

use App\Models\Contract;
use App\Models\File;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Services\File\FileService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Builds and renders the "Qo'shimcha kelishuv" (additional agreement) — a
 * numbered addendum to the order's service contract.
 *
 * {@see document()} is the single source for both the in-app accept drawer and
 * the PDF, mirroring {@see OrderContractService::document()}: the parties read
 * exactly what the stored document says.
 */
class AmendmentContractService
{
    /** Bump when the addendum template/terms change. */
    public const VERSION = 'v1';

    public function __construct(private readonly FileService $files) {}

    /**
     * Addendum document for a stored amendment.
     *
     * @return array<string, mixed>
     */
    public function documentFor(OrderAmendment $amendment): array
    {
        $amendment->loadMissing(['order.client', 'contract', 'offer.agentProfile', 'offer.agent']);

        return $this->build(
            order: $amendment->order,
            contract: $amendment->contract,
            before: $amendment->before_snapshot,
            after: $amendment->after_snapshot,
            initiatorRole: $amendment->initiator_role,
            reason: $amendment->reason,
            sequence: $amendment->sequence,
            requiresOperator: (bool) $amendment->requires_operator,
        );
    }

    /**
     * Addendum document for a proposal that has not been stored yet (preview).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    public function draftDocument(
        Order $order,
        ?Contract $contract,
        array $before,
        array $after,
        string $initiatorRole,
        ?string $reason,
        int $sequence,
        bool $requiresOperator,
    ): array {
        return $this->build($order, $contract, $before, $after, $initiatorRole, $reason, $sequence, $requiresOperator);
    }

    /**
     * Render the addendum PDF.
     *
     * @param  array<string, mixed>  $document
     * @param  list<array{label: string, name: string, accepted_at: string}>  $acceptances
     * @return array{file: File, hash: string}
     */
    public function render(array $document, array $acceptances = []): array
    {
        $pdf = Pdf::loadView('contracts.amendment_agreement', [
            'doc' => $document,
            'acceptances' => $acceptances,
        ])->setPaper('a4');

        $contents = $pdf->output();

        $file = $this->files->storeContents(
            $contents,
            'qoshimcha-kelishuv-'.str_replace('/', '-', (string) $document['number']).'.pdf',
            'application/pdf',
            $document['client_id'] ?? null,
            'contracts',
        );

        return ['file' => $file, 'hash' => hash('sha256', $contents)];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private function build(
        ?Order $order,
        ?Contract $contract,
        array $before,
        array $after,
        string $initiatorRole,
        ?string $reason,
        ?int $sequence,
        bool $requiresOperator,
    ): array {
        $delta = bcsub((string) ($after['total'] ?? '0'), (string) ($before['total'] ?? '0'), 2);
        $direction = match (true) {
            bccomp($delta, '0', 2) > 0 => 'charge',
            bccomp($delta, '0', 2) < 0 => 'refund',
            default => 'none',
        };

        $document = [
            'version' => self::VERSION,
            'number' => $this->number($contract, $order, $sequence),
            'sequence' => $sequence,
            'contract_number' => $contract?->number,
            'contract_date' => $contract?->generated_at?->format('d.m.Y'),
            'title' => "Qo'shimcha kelishuv",
            'subtitle' => $contract?->number !== null
                ? "№ {$contract->number} xizmat ko'rsatish shartnomasiga ilova"
                : "Xizmat ko'rsatish shartnomasiga ilova",
            'order_id' => $order?->id,
            'initiator_role' => $initiatorRole,
            'initiator_label' => $initiatorRole === OrderAmendment::ROLE_AGENT ? 'Ijrochi' : 'Buyurtmachi',
            'reason' => $reason,
            'agent' => $this->agentSnapshot($contract, $order),
            'client' => $this->clientSnapshot($contract, $order),
            'platform' => $this->platformSnapshot(),
            'before' => $before,
            'after' => $after,
            'delta' => $delta,
            'delta_direction' => $direction,
            'deadline_changed' => ($before['deadline_days'] ?? null) !== ($after['deadline_days'] ?? null),
            'requires_operator' => $requiresOperator,
            'client_id' => $order?->client_id,
            'generated_at' => now()->toIso8601String(),
        ];

        $document['intro'] = $this->intro($document);
        $document['sections'] = $this->sections($document);
        $document['hash'] = $this->hash($document);

        return $document;
    }

    private function number(?Contract $contract, ?Order $order, ?int $sequence): string
    {
        $base = $contract?->number ?? 'RB-'.($order?->id ?? 0).'-'.now()->format('Y');

        return $base.'/DS'.($sequence ?? 1);
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
        $contractLine = $document['contract_number'] !== null
            ? "{$document['contract_date']} sanadagi № {$document['contract_number']} xizmat ko'rsatish shartnomasining"
            : 'xizmat ko\'rsatish shartnomasining';

        return "Ijrochi — {$agent} va Buyurtmachi — {$client} (Operator — "
            .($document['platform']['legal_name'] ?: $document['platform']['name'])
            .' ishtirokida) ushbu qo\'shimcha kelishuvni tuzdilar. Ushbu hujjat '
            .$contractLine.' ajralmas qismi hisoblanadi. Tashabbuskor: '
            .$document['initiator_label'].'.';
    }

    /**
     * Clause text — the same blocks the drawer and the PDF render. Blocks are
     * numbered after the fact so the document never skips a clause number when
     * an optional one (e.g. the deadline) is absent.
     *
     * @param  array<string, mixed>  $document
     * @return list<array{key: string, heading: string, type: string, paragraphs: list<string>}>
     */
    private function sections(array $document): array
    {
        $money = fn ($v) => number_format(abs((float) $v), 0, '.', ' ')." so'm";
        $deadline = fn ($days) => $days === null ? '—' : "{$days} kun";

        $blocks = [
            [
                'key' => 'subject',
                'heading' => "O'zgartirish predmeti",
                'type' => 'items_after',
                'paragraphs' => ['Shartnomaning «Xizmatlar va narxi» bandi quyidagi tahrirda bayon etilsin:'],
            ],
            [
                'key' => 'previous',
                'heading' => "Oldingi tahrir (ma'lumot uchun)",
                'type' => 'items_before',
                'paragraphs' => ["O'zgartirishgacha amal qilgan ro'yxat: jami ".$money($document['before']['total'] ?? '0').'.'],
            ],
        ];

        if ($document['deadline_changed']) {
            $blocks[] = [
                'key' => 'deadline',
                'heading' => 'Bajarilish muddati',
                'type' => 'text',
                'paragraphs' => [
                    'Bajarilish muddati '.$deadline($document['before']['deadline_days'] ?? null)
                        .' dan '.$deadline($document['after']['deadline_days'] ?? null)." ga o'zgartirilsin.",
                ],
            ];
        }

        $financial = ["O'zgartirishdan keyingi umumiy qiymat: ".$money($document['after']['total'] ?? '0').'.'];

        if ($document['delta_direction'] === 'charge') {
            $financial[] = "Buyurtmachi qo'shimcha ".$money($document['delta'])
                ." miqdorida to'lovni Operator orqali amalga oshiradi. To'lov ilovadagi mavjud "
                ."usullardan biri bilan (karta, hisob/QR, naqd yoki bank o'tkazmasi) bajariladi.";
        } elseif ($document['delta_direction'] === 'refund') {
            $financial[] = 'Shartnoma qiymati '.$money($document['delta'])
                .' ga kamaytirilsin. Agar ushbu summa Buyurtmachi tomonidan '
                ."to'langan bo'lsa, Operator uni 3 (uch) ish kuni ichida qaytaradi; "
                ."to'lanmagan bo'lsa — to'lanadigan summa mos ravishda kamayadi.";
        } else {
            $financial[] = "Shartnoma qiymati o'zgarmaydi.";
        }

        $blocks[] = [
            'key' => 'financial',
            'heading' => 'Moliyaviy natija',
            'type' => 'text',
            'paragraphs' => $financial,
        ];

        $blocks[] = [
            'key' => 'unchanged',
            'heading' => "O'zgarmagan shartlar",
            'type' => 'text',
            'paragraphs' => [
                "Shartnomaning ushbu kelishuvda ko'rsatilmagan barcha qolgan shartlari o'zgarishsiz kuchda qoladi.",
                'Operatorning roli, komissiyasi va javobgarlik taqsimoti asosiy shartnomadagidek qoladi.',
            ],
        ];

        $blocks[] = [
            'key' => 'force',
            'heading' => 'Kuchga kirishi',
            'type' => 'text',
            'paragraphs' => array_values(array_filter([
                "Ushbu qo'shimcha kelishuv Tomonlarning ilovadagi elektron aksepti (tasdiqlash tugmasi) "
                    ."daqiqasidan kuchga kiradi va shartnomaning ajralmas qismi bo'ladi.",
                $document['requires_operator']
                    ? "O'zgartirish muddatga ta'sir qilgani yoki dastlabki ro'yxat doirasidan chiqqani "
                        ."sababli Operator tasdig'i ham talab qilinadi."
                    : null,
                "Elektron aksept yozuvi (vaqt, IP, hujjat hash'i) Tomonlar uchun yozma shaklga tenglashtiriladi.",
            ])),
        ];

        $blocks[] = [
            'key' => 'requisites',
            'heading' => 'Tomonlarning rekvizitlari',
            'type' => 'parties',
            'paragraphs' => [],
        ];

        // Number the clauses and their paragraphs (1., 1.1., 1.2., 2., …).
        return array_values(array_map(function (array $block, int $index): array {
            $n = $index + 1;

            return [
                'key' => $block['key'],
                'heading' => "{$n}. {$block['heading']}",
                'type' => $block['type'],
                'paragraphs' => array_values(array_map(
                    fn (string $text, int $i): string => "{$n}.".($i + 1).". {$text}",
                    $block['paragraphs'],
                    array_keys($block['paragraphs']),
                )),
            ];
        }, $blocks, array_keys($blocks)));
    }

    /**
     * @return array<string, mixed>
     */
    private function agentSnapshot(?Contract $contract, ?Order $order): array
    {
        // The contract froze the requisites the parties signed — reuse them.
        $snapshot = $contract?->agent_snapshot;

        if (is_array($snapshot) && $snapshot !== []) {
            return $snapshot;
        }

        $offer = $order?->acceptedOffer;
        $profile = $offer?->agentProfile;
        $agent = $offer?->agent;

        return [
            'company_name' => $profile?->company_name
                ?: trim(($agent?->first_name ?? '').' '.($agent?->last_name ?? '')),
            'inn' => $profile?->inn,
            'phone' => $profile?->phone ?? $agent?->phone,
            'address' => $profile?->location_label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientSnapshot(?Contract $contract, ?Order $order): array
    {
        $snapshot = $contract?->client_snapshot;

        if (is_array($snapshot) && $snapshot !== []) {
            return $snapshot;
        }

        $client = $order?->client;

        return [
            'name' => trim(($client?->first_name ?? '').' '.($client?->last_name ?? '')),
            'phone' => $client?->phone,
            'is_legal_entity' => false,
            'company_name' => null,
            'inn' => null,
        ];
    }

    /**
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
            'phone' => $platform['phone'] ?? null,
            'email' => $platform['email'] ?? null,
            'role' => "Marketplace va to'lov operatori",
        ];
    }

    /**
     * Stable fingerprint of the accepted text (volatile fields excluded).
     *
     * @param  array<string, mixed>  $document
     */
    private function hash(array $document): string
    {
        $core = [
            'version' => $document['version'],
            'number' => $document['number'],
            'contract_number' => $document['contract_number'],
            'agent' => $document['agent'],
            'client' => $document['client'],
            'before' => $document['before'],
            'after' => $document['after'],
            'delta' => $document['delta'],
            'intro' => $document['intro'],
            'sections' => $document['sections'],
        ];

        return hash('sha256', (string) json_encode($core, JSON_UNESCAPED_UNICODE));
    }
}
