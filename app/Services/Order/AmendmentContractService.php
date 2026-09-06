<?php

namespace App\Services\Order;

use App\Models\File;
use App\Models\OrderAmendment;
use App\Services\File\FileService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders the immutable "Additional agreement" (Qo'shimcha kelishuv) PDF from an
 * amendment's before/after snapshots. Mirrors {@see OrderContractService}: the
 * document is proof; binding force comes from the in-app approvals.
 */
class AmendmentContractService
{
    /** Bump when the amendment template/terms change. */
    public const VERSION = 'v1';

    public function __construct(private readonly FileService $files) {}

    /**
     * @return array{file: File, hash: string}
     */
    public function render(OrderAmendment $amendment): array
    {
        $amendment->loadMissing(['order.client', 'offer.agentProfile', 'offer.agent', 'initiator']);

        $order = $amendment->order;
        $offer = $amendment->offer;
        $before = $amendment->before_snapshot;
        $after = $amendment->after_snapshot;

        $agentName = $offer?->agentProfile?->company_name
            ?? trim(($offer?->agent?->first_name ?? '').' '.($offer?->agent?->last_name ?? ''));
        $clientName = trim(($order?->client?->first_name ?? '').' '.($order?->client?->last_name ?? ''));

        $pdf = Pdf::loadView('contracts.amendment_agreement', [
            'number' => $this->number($amendment),
            'contractNumber' => 'RB-'.$amendment->order_id.'-'.$amendment->created_at->format('Y'),
            'generatedAt' => now(),
            'agentName' => $agentName,
            'clientName' => $clientName ?: null,
            'initiatorLabel' => $amendment->initiator_role === OrderAmendment::ROLE_AGENT ? 'Ijrochi' : 'Buyurtmachi',
            'reason' => $amendment->reason,
            'before' => $before,
            'after' => $after,
            'extraAmount' => $amendment->extra_amount,
            'beforeDeadline' => $this->deadlineLabel($before['deadline_days'] ?? null),
            'afterDeadline' => $this->deadlineLabel($after['deadline_days'] ?? null),
            'requiresFormalDoc' => $amendment->requires_formal_doc,
        ])->setPaper('a4');

        $contents = $pdf->output();

        $file = $this->files->storeContents(
            $contents,
            "qoshimcha-kelishuv-{$amendment->id}.pdf",
            'application/pdf',
            $order?->client_id,
            'contracts',
        );

        return ['file' => $file, 'hash' => hash('sha256', $contents)];
    }

    private function number(OrderAmendment $amendment): string
    {
        return 'RB-'.$amendment->order_id.'-DS'.$amendment->id;
    }

    private function deadlineLabel(?int $days): ?string
    {
        return $days === null ? null : "{$days} kun";
    }
}
