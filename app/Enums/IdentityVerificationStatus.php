<?php

namespace App\Enums;

/**
 * State of an optional MyID identity check. A record exists only once a session
 * has been started, so absence of a record means "never attempted".
 *
 * Pending  — session created, waiting for the user to finish MyID + finalize.
 * Verified — MyID confirmed a live, matching identity (badge granted).
 * Failed   — the last attempt failed (liveness/recognition/…); user may retry.
 */
enum IdentityVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
}
