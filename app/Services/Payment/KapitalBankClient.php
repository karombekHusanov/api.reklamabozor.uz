<?php

namespace App\Services\Payment;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP wrapper over the Kapitalbank (bank24.uz) OpenAPI.
 *
 * `GetDoc1C` (account statement lookup) matches incoming bank transfers to
 * orders by contract number. `SendPaymentIBK` creates an (unsigned) payment
 * order in Kapitalbank's internet-bank system — per the bank's guidance this
 * is "input only": actual signing/sending stays a manual step on their
 * website (ECP + OTP, with a bulk-confirm option). Fully headless sending
 * (`SendPayment`) is a separate, still-blocked phase — see CLAUDE.md §12.
 *
 * Auth: server-to-server, static-IP whitelisted — every request carries
 * `Authorization: Basic base64(login:password)` directly. No `APILogin`/`sid`
 * session dance is needed for these calls (the docs allow either sid or Basic
 * auth per request for most methods, including GetDoc1C and SendPaymentIBK).
 */
class KapitalBankClient
{
    /**
     * Fetch the account statement for a given day (defaults to today).
     *
     * @return list<array<string, mixed>> the `result.content` rows (dir=2 =
     *                                    incoming/credit, dir=1 = outgoing/debit)
     */
    public function getStatement(?string $date = null): array
    {
        $this->assertConfigured();

        $payload = [
            'branch' => (string) config('kapitalbank.branch'),
            'account' => (string) config('kapitalbank.account'),
        ];

        if ($date !== null) {
            $payload['date'] = $date;
        }

        $response = Http::asJson()
            ->withBasicAuth((string) config('kapitalbank.login'), (string) config('kapitalbank.password'))
            ->post($this->baseUrl().'/GetDoc1C', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Kapitalbank GetDoc1C request failed: '.$response->status().' '.$response->body());
        }

        $error = $response->json('error');

        if (is_array($error) && ($error['code'] ?? null) !== null) {
            $code = $error['code'] ?? 'unknown';
            $message = $error['message'] ?? 'unknown error';

            throw new RuntimeException("Kapitalbank GetDoc1C error {$code}: {$message}");
        }

        $content = $response->json('result.content');

        return is_array($content) ? $content : [];
    }

    /**
     * Convenience: statement for a specific day offset from today (0 = today).
     */
    public function getStatementForDaysAgo(int $daysAgo): array
    {
        return $this->getStatement(Carbon::today()->subDays($daysAgo)->format('d.m.Y'));
    }

    /**
     * Create an unsigned payment order in Kapitalbank's internet-bank system.
     * Per the bank's guidance this only queues the order for input — it does
     * NOT move money; a human still signs and sends it from the Kapitalbank
     * website (ECP + OTP, batch confirm supported).
     *
     * @param  array<string, mixed>  $document  SendPaymentIBK's payment.document fields
     *                                          (mfo_ct/acc_ct/name_ct/inn_ct/amount/purpose/uniq/...)
     * @return array{result: int|null, error: array<string, mixed>|null}
     */
    public function sendPaymentIBK(array $document): array
    {
        $this->assertConfigured();
        $this->assertPayoutConfigured();

        $payload = [
            'client_id' => config('kapitalbank.client_id'),
            'payment' => [
                'document' => array_merge([
                    'mfo_dt' => (string) config('kapitalbank.branch'),
                    'acc_dt' => (string) config('kapitalbank.account'),
                    'name_dt' => (string) config('kapitalbank.sender_name'),
                    'inn_dt' => (string) config('kapitalbank.sender_inn'),
                    'purp_code' => (string) config('kapitalbank.payout_purpose_code'),
                    'dtype' => (string) config('kapitalbank.payout_document_type'),
                    'dir' => 1, // outgoing
                    'anor' => config('kapitalbank.payout_anor') ? 1 : 0,
                ], $document),
                // Unsigned by design — see class docblock. The bank signs/sends
                // these from their own website, not via this API call.
                'signs' => [
                    ['method' => 0, 'sert_num' => null, 'signature' => null],
                ],
            ],
        ];

        $response = Http::asJson()
            ->withBasicAuth((string) config('kapitalbank.login'), (string) config('kapitalbank.password'))
            ->post($this->baseUrl().'/SendPaymentIBK', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Kapitalbank SendPaymentIBK request failed: '.$response->status().' '.$response->body());
        }

        $error = $response->json('error');

        if (is_array($error) && ($error['code'] ?? null) !== null) {
            $code = $error['code'] ?? 'unknown';
            $message = $error['message'] ?? 'unknown error';

            throw new RuntimeException("Kapitalbank SendPaymentIBK error {$code}: {$message}");
        }

        return [
            'result' => $response->json('result'),
            'error' => $error,
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('kapitalbank.base_url'), '/');
    }

    /**
     * This integration must be explicitly opted into (env) before it is live
     * — never silently no-op when misconfigured, since that would look like
     * "no incoming transfers today" instead of "not set up".
     */
    private function assertConfigured(): void
    {
        if (! config('kapitalbank.enabled')) {
            throw new RuntimeException('Kapitalbank integration is disabled (KAPITALBANK_ENABLED=false).');
        }

        foreach (['login', 'password', 'branch', 'account'] as $key) {
            if (blank(config("kapitalbank.{$key}"))) {
                throw new RuntimeException("Kapitalbank integration is misconfigured: kapitalbank.{$key} is missing.");
            }
        }
    }

    /** Additional config only SendPaymentIBK needs — kept separate so GetDoc1C stays unaffected. */
    private function assertPayoutConfigured(): void
    {
        foreach (['client_id', 'sender_name', 'sender_inn', 'payout_purpose_code'] as $key) {
            if (blank(config("kapitalbank.{$key}"))) {
                throw new RuntimeException("Kapitalbank payout queueing is misconfigured: kapitalbank.{$key} is missing.");
            }
        }
    }
}
