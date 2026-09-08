<?php

namespace App\Http\Requests\Api\V1\Agent;

/**
 * Agent previews the per-order contract built from the pricelist rows they are
 * composing — nothing is stored; the same rows are then sent (and accepted) via
 * {@see SetOfferPricelistRequest}.
 */
class PreviewOfferContractRequest extends SetOfferPricelistRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        // Consent belongs to the send step, not the preview.
        unset($rules['accept_contract']);

        return $rules;
    }
}
