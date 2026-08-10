<?php

namespace App\Services\Order;

use App\Enums\LegalEntityStatus;
use App\Enums\PersonType;
use App\Models\Contract;
use App\Models\Offer;
use App\Models\Order;
use App\Services\File\FileService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Generates the per-order service contract (client ↔ agent) once the deal is
 * active. Requisites and line items are snapshotted so the document is immutable.
 * The platform appears only as the payment operator, not a signing party.
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

        $order->loadMissing(['client.legalEntityVerification', 'category']);

        $agentSnapshot = $this->agentSnapshot($offer);
        $clientSnapshot = $this->clientSnapshot($order);
        $itemsSnapshot = $this->itemsSnapshot($offer);
        $number = $this->number($order);

        $pdf = Pdf::loadView('contracts.order_agreement', [
            'order' => $order,
            'number' => $number,
            'agent' => $agentSnapshot,
            'client' => $clientSnapshot,
            'items' => $itemsSnapshot,
            'total' => (string) ($offer->price ?? '0'),
            'generatedAt' => now(),
            'deadlineLabel' => $this->deadlineLabel($order),
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
            'number' => $number,
            'total' => $offer->price ?? 0,
            'client_snapshot' => $clientSnapshot,
            'agent_snapshot' => $agentSnapshot,
            'items_snapshot' => $itemsSnapshot,
            'pdf_file_id' => $file->id,
            'hash' => hash('sha256', $contents),
            'version' => self::VERSION,
            'generated_at' => now(),
        ]);
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
                ?? trim(($agent?->first_name ?? '').' '.($agent?->last_name ?? '')),
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
    private function clientSnapshot(Order $order): array
    {
        $client = $order->client;
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

    private function number(Order $order): string
    {
        return 'RB-'.$order->id.'-'.now()->format('Y');
    }

    private function deadlineLabel(Order $order): ?string
    {
        return match ($order->deadline?->value) {
            'today_tomorrow' => 'Bugun-erta',
            'this_week' => 'Shu hafta',
            default => null,
        };
    }
}
