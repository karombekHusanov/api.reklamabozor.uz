<?php

namespace App\Http\Requests\Api\V1\Agent;

/**
 * An individual executor's request for the legal-entity status carries the
 * full legal KYC + the agency offer acceptance — the same rules as the agent
 * application form.
 */
class StoreAgentProfileUpgradeRequest extends StoreAgentProfileRequest {}
