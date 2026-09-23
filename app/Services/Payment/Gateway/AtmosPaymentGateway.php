<?php

namespace App\Services\Payment\Gateway;

use App\Contracts\CardPaymentGateway;
use App\Contracts\PaymentGateway;
use App\Enums\GatewayPaymentStatus;
use App\Models\GatewayPayment;
use App\Services\Payment\Atmos\AtmosClient;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * ATMOS, two ways to pay:
 *  - hosted checkout — the customer enters the card on ATMOS's page;
 *  - in-app card form ({@see CardPaymentGateway}) — merchant create →
 *    pre-apply (card + expiry, ATMOS texts an SMS code) → apply (code). The
 *    card data only passes through to ATMOS; it is never stored or logged.
 *    Card transactions carry a `card:` gateway_ref prefix so status checks
 *    hit the merchant endpoint instead of the invoice one.
 *
 * ATMOS's "callback" is a pre-debit billing check, NOT a paid notification:
 * {@see verifyCallback()} only authenticates it. Money is confirmed solely by
 * {@see getStatus()} (invoice/get: success + final).
 */
class AtmosPaymentGateway implements CardPaymentGateway, PaymentGateway
{
    private const CARD_PREFIX = 'card:';

    public function __construct(private readonly AtmosClient $client) {}

    public function name(): string
    {
        return 'atmos';
    }

    public function createPayment(int $amountTiyin, string $reference, ?string $returnUrl = null): GatewayCheckout
    {
        $invoice = $this->client->createInvoice(
            requestId: $reference,
            account: $reference,
            amountTiyin: $amountTiyin,
            successUrl: (string) (config('atmos.success_url') ?: $returnUrl),
            items: [$this->fiscalItem($amountTiyin)],
            ttlMinutes: (int) config('atmos.invoice_ttl'),
        );

        return new GatewayCheckout($invoice['payment_id'], $invoice['url']);
    }

    /**
     * Authenticate the billing check. Returns a Pending event for the known
     * payment (never Success — see class doc). Throws on any mismatch.
     */
    public function verifyCallback(Request $request): GatewayEvent
    {
        $this->assertAllowedIp($request);

        $storeId = (string) $request->input('store_id');
        $transactionId = (string) $request->input('transaction_id');
        $account = (string) $request->input('account');
        $amount = (string) $request->input('amount');
        $sign = (string) $request->input('sign');

        if ($account === '' || $sign === '' || $amount === '' || ! ctype_digit($amount)) {
            throw new InvalidArgumentException('Malformed ATMOS callback.');
        }

        if ($storeId !== (string) config('atmos.store_id')) {
            throw new InvalidArgumentException('ATMOS callback for a different store.');
        }

        $expected = hash((string) config('atmos.sign_algo'), $storeId.$transactionId.$account.$amount.config('atmos.api_key'));

        if (empty(config('atmos.api_key')) || ! hash_equals($expected, strtolower($sign))) {
            throw new InvalidArgumentException('Invalid ATMOS signature.');
        }

        $payment = GatewayPayment::query()->where('reference', $account)->first();

        if ($payment === null || $payment->gateway_ref === null) {
            throw new InvalidArgumentException('Unknown ATMOS account.');
        }

        return new GatewayEvent($payment->gateway_ref, GatewayPaymentStatus::Pending, (int) $amount);
    }

    public function startCardPayment(int $amountTiyin, string $reference): string
    {
        $item = $this->fiscalItem($amountTiyin);
        $ofd = isset($item['code']) ? [[
            'ofd_code' => $item['code'],
            'name' => $item['name'],
            'amount' => $amountTiyin,
            'details' => array_map(
                fn (array $d) => ['key' => $d['name'], 'value' => $d['values'], 'status' => 0, 'type' => 0],
                $item['details'] ?? [],
            ),
        ]] : [];

        return self::CARD_PREFIX.$this->client->merchantCreate($reference, $amountTiyin, $ofd);
    }

    public function sendCardOtp(string $gatewayRef, string $cardNumber, string $expiryYymm): void
    {
        $this->client->merchantPreApply($this->cardTransaction($gatewayRef), $cardNumber, $expiryYymm);
    }

    public function confirmCardOtp(string $gatewayRef, string $otp): GatewayEvent
    {
        $tx = $this->client->merchantApply($this->cardTransaction($gatewayRef), $otp);

        return $this->cardEvent($gatewayRef, $tx);
    }

    public function getStatus(string $gatewayRef): GatewayEvent
    {
        if (str_starts_with($gatewayRef, self::CARD_PREFIX)) {
            try {
                $tx = $this->client->merchantGet($this->cardTransaction($gatewayRef));
            } catch (CardPaymentException) {
                // Closed / unknown — never paid; the reconcile sweep expires it.
                return new GatewayEvent($gatewayRef, GatewayPaymentStatus::Pending, null);
            }

            return $this->cardEvent($gatewayRef, $tx);
        }

        $invoice = $this->client->getInvoice($gatewayRef);

        $final = (bool) ($invoice['final'] ?? false);
        $success = (bool) ($invoice['success'] ?? false) || strtoupper((string) ($invoice['state'] ?? '')) === 'SUCCESS';

        $status = match (true) {
            $final && $success => GatewayPaymentStatus::Success,
            $final => GatewayPaymentStatus::Failed,
            default => GatewayPaymentStatus::Pending,
        };

        return new GatewayEvent($gatewayRef, $status, isset($invoice['amount']) ? (int) $invoice['amount'] : null);
    }

    /**
     * Paid only when ATMOS confirms the debit — a success id or `confirmed`.
     *
     * @param  array<string, mixed>  $tx
     */
    private function cardEvent(string $gatewayRef, array $tx): GatewayEvent
    {
        $paid = ! empty($tx['success_trans_id']) || ($tx['confirmed'] ?? false) === true;

        return new GatewayEvent(
            $gatewayRef,
            $paid ? GatewayPaymentStatus::Success : GatewayPaymentStatus::Pending,
            isset($tx['amount']) ? (int) $tx['amount'] : null,
        );
    }

    private function cardTransaction(string $gatewayRef): string
    {
        return substr($gatewayRef, strlen(self::CARD_PREFIX));
    }

    /** @return array<string, mixed> */
    private function fiscalItem(int $amountTiyin): array
    {
        $details = [];

        if ($code = config('atmos.package_code')) {
            $details[] = ['name' => 'package_code', 'values' => (string) $code];
        }

        if ($tin = config('legal.platform.inn')) {
            $details[] = ['name' => 'tin', 'values' => (string) $tin];
        }

        return array_filter([
            'items_id' => '1',
            'code' => config('atmos.ofd_code'),
            'name' => config('atmos.item_name'),
            'amount' => $amountTiyin,
            'quantity' => 1,
            'details' => $details,
        ], fn ($v) => $v !== null);
    }

    private function assertAllowedIp(Request $request): void
    {
        $allowed = config('atmos.callback_ips');

        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            throw new InvalidArgumentException('ATMOS callback from a disallowed IP.');
        }
    }
}
