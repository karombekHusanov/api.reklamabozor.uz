<?php

namespace App\Http\Requests\Api\V1\Agent;

use App\Services\Pass\PassSettings;

/**
 * Wallet top-up from the in-app card form: the card step plus an amount. The
 * minimum is one otklik fee so a top-up always buys at least one response.
 */
class StartWalletTopupRequest extends StartCardPaymentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $min = max(1, (int) app(PassSettings::class)->get('response_price_som'));

        return parent::rules() + [
            'amount_som' => ['required', 'integer', "min:{$min}", 'max:5000000'],
        ];
    }
}
