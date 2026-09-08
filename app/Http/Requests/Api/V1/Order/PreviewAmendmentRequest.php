<?php

namespace App\Http\Requests\Api\V1\Order;

/**
 * Preview the addendum built from draft rows — nothing is stored, so the
 * consent flag belongs to the send step, not here.
 */
class PreviewAmendmentRequest extends StoreAmendmentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['accept_contract']);

        return $rules;
    }
}
