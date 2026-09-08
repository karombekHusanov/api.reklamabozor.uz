<?php

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Models\File;
use App\Models\Order;
use App\Models\Payment;
use App\Services\File\FileService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders the invoice (hisob-faktura / to'lov hisobi) a client pays outside the
 * gateway: cash at the platform's desk, or a bank transfer from a company
 * account. The document carries the platform's requisites as the payee — the
 * platform collects as payment operator and pays the agent out afterwards.
 */
class InvoiceService
{
    /** Bump when the invoice template changes. */
    public const VERSION = 'v1';

    public function __construct(private readonly FileService $files) {}

    public function generateForPayment(Payment $payment, Order $order): File
    {
        $order->loadMissing(['client.legalEntityVerification', 'contract', 'acceptedOffer.items', 'acceptedOffer.agentProfile']);

        $pdf = Pdf::loadView('invoices.order_invoice', [
            'number' => $this->number($payment, $order),
            'issuedAt' => now(),
            'dueAt' => $order->payment_due_at,
            'payment' => $payment,
            'order' => $order,
            'method' => $payment->method,
            'isCash' => $payment->method === PaymentMethod::Cash,
            'platform' => config('legal.platform', []),
            'payer' => $this->payerSnapshot($order),
            'items' => $this->items($order),
            'total' => number_format($payment->amountSom(), 0, '.', ' '),
            'contractNumber' => $order->contract?->number,
        ])->setPaper('a4');

        $contents = $pdf->output();

        return $this->files->storeContents(
            $contents,
            "hisob-{$order->id}-{$payment->id}.pdf",
            'application/pdf',
            $order->client_id,
            'invoices',
        );
    }

    public function number(Payment $payment, Order $order): string
    {
        return 'INV-'.$order->id.'-'.$payment->id;
    }

    /**
     * Who owes the money: the client, with company requisites when they are a
     * verified legal entity (an accountant needs them on the invoice).
     *
     * @return array<string, mixed>
     */
    private function payerSnapshot(Order $order): array
    {
        // The contract froze these at deal time — reuse them so the invoice and
        // the contract never disagree.
        $snapshot = $order->contract?->client_snapshot;

        if (is_array($snapshot) && $snapshot !== []) {
            return $snapshot;
        }

        $client = $order->client;

        return [
            'name' => trim(($client?->first_name ?? '').' '.($client?->last_name ?? '')),
            'phone' => $client?->phone,
            'is_legal_entity' => false,
            'company_name' => null,
            'inn' => null,
        ];
    }

    /**
     * Invoice lines — the contract's frozen pricelist, falling back to the
     * accepted offer's items.
     *
     * @return list<array<string, mixed>>
     */
    private function items(Order $order): array
    {
        $snapshot = $order->contract?->items_snapshot;

        if (is_array($snapshot) && $snapshot !== []) {
            return $snapshot;
        }

        $offer = $order->acceptedOffer;
        $items = $offer?->items ?? collect();

        if ($items->isEmpty()) {
            return [[
                'name' => $order->title ?: 'Reklama xizmati',
                'unit' => 'dona',
                'quantity' => '1',
                'unit_price' => (string) ($offer?->price ?? '0'),
                'line_total' => (string) ($offer?->price ?? '0'),
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
}
