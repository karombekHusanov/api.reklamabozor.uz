<?php

namespace App\Services\Agent;

use App\Enums\AgentContractStatus;
use App\Models\AgentProfile;
use App\Models\File;
use App\Services\File\FileService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\ValidationException;

/**
 * Generates the platform ↔ agent agreement (Qatlam 2) from the agent's KYC data
 * and manages the signed-scan handshake: generate → agent signs offline →
 * re-uploads → manager approves. See {@see AgentContractStatus}.
 */
class AgentContractService
{
    /** Bump when the agreement template/terms change (forces re-sign on resubmit). */
    public const VERSION = 'v1';

    public function __construct(private readonly FileService $files) {}

    /**
     * Render the agreement PDF from the profile's KYC data, store it, and reset
     * the contract to "awaiting signature". Any previously signed scan is
     * discarded — the agent must sign the fresh version. No-op for designers.
     */
    public function generateFor(AgentProfile $profile): AgentProfile
    {
        if (! $profile->requiresContract()) {
            return $profile;
        }

        $profile->loadMissing('user');

        $pdf = Pdf::loadView('contracts.agent_agreement', [
            'profile' => $profile,
            'version' => self::VERSION,
            'generatedAt' => now(),
            'commissionPercent' => (float) config('services.multicard.commission_percent', 7),
        ])->setPaper('a4');

        $contents = $pdf->output();

        $file = $this->files->storeContents(
            $contents,
            "shartnoma-{$profile->id}-".self::VERSION.'.pdf',
            'application/pdf',
            $profile->user_id,
            'contracts',
        );

        // Drop the previous generated file + any stale signed scan.
        $this->deleteFileIfSet($profile->contract_file_id);
        $this->deleteFileIfSet($profile->signed_contract_file_id);

        $profile->forceFill([
            'contract_file_id' => $file->id,
            'contract_hash' => hash('sha256', $contents),
            'contract_version' => self::VERSION,
            'contract_generated_at' => now(),
            'contract_status' => AgentContractStatus::AwaitingSignature,
            'signed_contract_file_id' => null,
            'contract_signed_at' => null,
            'contract_rejection_reason' => null,
        ])->save();

        return $profile;
    }

    /**
     * Agent uploads the signed (wet-signature + stamp) scan → under review.
     * Allowed only while awaiting signature or after a rejection.
     */
    public function uploadSigned(AgentProfile $profile, File $file): AgentProfile
    {
        if (! $profile->requiresContract()) {
            throw ValidationException::withMessages([
                'contract' => ['This profile does not require a signed agreement.'],
            ]);
        }

        if (! in_array($profile->contract_status, [
            AgentContractStatus::AwaitingSignature,
            AgentContractStatus::Rejected,
        ], true)) {
            throw ValidationException::withMessages([
                'contract' => ['The signed agreement cannot be uploaded in the current state.'],
            ]);
        }

        if ((int) $file->uploaded_by !== (int) $profile->user_id) {
            throw ValidationException::withMessages([
                'file_id' => ['You can only attach your own upload.'],
            ]);
        }

        // Replace a previously uploaded (rejected) scan.
        if ($profile->signed_contract_file_id !== null && $profile->signed_contract_file_id !== $file->id) {
            $this->deleteFileIfSet($profile->signed_contract_file_id);
        }

        $profile->forceFill([
            'signed_contract_file_id' => $file->id,
            'contract_signed_at' => now(),
            'contract_status' => AgentContractStatus::UnderReview,
            'contract_rejection_reason' => null,
        ])->save();

        return $profile->load(AgentProfile::PROFILE_RELATIONS);
    }

    private function deleteFileIfSet(?int $fileId): void
    {
        if ($fileId === null) {
            return;
        }

        $file = File::find($fileId);
        if ($file !== null) {
            $this->files->delete($file);
        }
    }
}
