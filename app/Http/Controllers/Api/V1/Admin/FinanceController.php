<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Services\Admin\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin Finance → reporting. The summary backs the overview page; the two
 * registers are the CSV the bookkeeping actually works from (incoming client
 * money, outgoing agent transfers with full bank requisites).
 */
class FinanceController extends ApiController
{
    public function __construct(
        private readonly FinanceService $finance,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->finance->period(
            $request->query('from') ? (string) $request->query('from') : null,
            $request->query('to') ? (string) $request->query('to') : null,
        );

        return $this->success($this->finance->summary($from, $to));
    }

    public function paymentsRegister(Request $request): StreamedResponse
    {
        $rows = $this->finance->paymentsRegister($request->query());

        return $this->csv('tolovlar-reestri', [
            'id' => 'ID',
            'created_at' => 'Yaratilgan',
            'paid_at' => "To'langan",
            'order_id' => 'Buyurtma',
            'order_title' => 'Buyurtma nomi',
            'payer' => "To'lovchi",
            'payer_phone' => 'Telefon',
            'method' => 'Usul',
            'status' => 'Holat',
            'amount_som' => "Summa, so'm",
            'reference' => 'Hujjat/UUID',
            'refunded_at' => 'Qaytarilgan',
        ], $rows);
    }

    public function payoutsRegister(Request $request): StreamedResponse
    {
        $rows = $this->finance->payoutsRegister($request->query());

        return $this->csv('chiqimlar-reestri', [
            'id' => 'ID',
            'created_at' => 'Yaratilgan',
            'paid_at' => "O'tkazilgan",
            'order_id' => 'Buyurtma',
            'tranche' => 'Transh',
            'status' => 'Holat',
            'agent' => 'Qabul qiluvchi',
            'inn' => 'INN',
            'bank_name' => 'Bank',
            'bank_account' => 'Hisob raqami',
            'mfo' => 'MFO',
            'amount_som' => "Summa, so'm",
            'reference' => "To'lov topshirig'i",
        ], $rows);
    }

    public function gatewayPaymentsRegister(Request $request): StreamedResponse
    {
        $rows = $this->finance->gatewayPaymentsRegister($request->query());

        return $this->csv('propusk-tolovlari-reestri', [
            'id' => 'ID',
            'paid_at' => "To'langan",
            'agent' => "To'lovchi",
            'phone' => 'Telefon',
            'purpose' => 'Maqsad',
            'gateway' => 'Shlyuz',
            'amount_som' => "Summa, so'm",
            'card' => 'Karta',
            'reference' => 'Tranzaksiya',
        ], $rows);
    }

    /**
     * Excel on a Uzbek locale opens semicolon-separated UTF-8 with a BOM
     * correctly; a comma-separated file lands in one column.
     *
     * @param  array<string, string>  $columns
     * @param  list<array<string, string>>  $rows
     */
    private function csv(string $name, array $columns, array $rows): StreamedResponse
    {
        $filename = $name.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($columns, $rows): void {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_values($columns), ';');

            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    static fn (string $key): string => (string) ($row[$key] ?? ''),
                    array_keys($columns),
                ), ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
