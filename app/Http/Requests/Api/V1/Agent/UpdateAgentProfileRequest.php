<?php

namespace App\Http\Requests\Api\V1\Agent;

/**
 * Resubmitting / editing an agent application sends the full payload,
 * so the validation rules are identical to the store request.
 */
class UpdateAgentProfileRequest extends StoreAgentProfileRequest {}
