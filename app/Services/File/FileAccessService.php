<?php

namespace App\Services\File;

use App\Enums\OrderDocumentType;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\AgentProfileUpgrade;
use App\Models\Contract;
use App\Models\File;
use App\Models\LegalEntityVerification;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\OrderDocument;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Who may read a file through GET /files/{file}.
 *
 * Admins see everything. A file attached to a registered private resource
 * ({@see FileService::PRIVATE_FILE_COLUMNS}) is governed ONLY by that
 * resource's participants — the uploader rule does not apply, because the
 * platform generates documents on the client's behalf (uploaded_by = client)
 * that the client must not see (the commission act). An unattached file is
 * readable by whoever uploaded it, and by anyone when it is public anyway.
 */
class FileAccessService
{
    public function canView(User $user, File $file): bool
    {
        if ($user->role === Role::Admin) {
            return true;
        }

        $references = $this->references($file);

        if ($references->isEmpty()) {
            return ! $file->is_private || (int) $file->uploaded_by === $user->id;
        }

        return $references->contains(fn (Model $model): bool => $this->isParticipant($user, $model, $file));
    }

    /**
     * Every registered private resource row pointing at this file.
     *
     * @return Collection<int, Model>
     */
    public function references(File $file): Collection
    {
        $references = new Collection;

        foreach (FileService::PRIVATE_FILE_COLUMNS as $class => $columns) {
            /** @var class-string<Model> $class */
            $rows = $class::query()
                ->where(function ($query) use ($columns, $file): void {
                    foreach ($columns as $column) {
                        $query->orWhere($column, $file->id);
                    }
                })
                ->get();

            $references = $references->merge($rows);
        }

        return $references;
    }

    private function isParticipant(User $user, Model $model, File $file): bool
    {
        return match (true) {
            $model instanceof AgentProfile => (int) $model->user_id === $user->id,
            $model instanceof AgentProfileUpgrade => (int) $model->user_id === $user->id,
            $model instanceof LegalEntityVerification => (int) $model->user_id === $user->id,
            $model instanceof Contract,
            $model instanceof OrderAmendment => $this->isOrderParty($user, $model->order, (int) $model->offer?->agent_id),
            $model instanceof OrderDocument => $this->canSeeDocument($user, $model),
            $model instanceof Payment => $model->payable instanceof Order
                && $this->isOrderParty($user, $model->payable, (int) $model->payable->acceptedOffer()->value('agent_id')),
            default => false,
        };
    }

    private function canSeeDocument(User $user, OrderDocument $document): bool
    {
        $agentId = (int) $document->offer?->agent_id;

        // The commission act is between the platform and the agent — the client
        // has no business seeing what the agent was charged.
        if ($document->type === OrderDocumentType::CommissionAct) {
            return $agentId === $user->id;
        }

        return $this->isOrderParty($user, $document->order, $agentId);
    }

    private function isOrderParty(User $user, ?Order $order, int $agentId): bool
    {
        if ($order === null) {
            return false;
        }

        return (int) $order->client_id === $user->id || ($agentId !== 0 && $agentId === $user->id);
    }
}
