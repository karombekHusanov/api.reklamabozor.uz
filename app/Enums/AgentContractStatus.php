<?php

namespace App\Enums;

/**
 * Lifecycle of the platform ↔ agent agreement (Qatlam 2). Generated from the
 * agent's KYC data, signed offline by the agent, re-uploaded, then approved by
 * a manager. An agent cannot operate (send interest/offers) until it is approved.
 */
enum AgentContractStatus: string
{
    // Contract generated from KYC data; waiting for the agent to sign + upload.
    case AwaitingSignature = 'awaiting_signature';
    // Agent uploaded the signed scan; waiting for a manager to review it.
    case UnderReview = 'under_review';
    // Manager approved the signed contract — the agent may be activated.
    case Approved = 'approved';
    // Manager rejected the signed scan (e.g. missing signature/stamp) — re-upload.
    case Rejected = 'rejected';
}
