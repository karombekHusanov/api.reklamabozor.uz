<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PassMode;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\AdjustWalletRequest;
use App\Http\Requests\Api\V1\Admin\GrantPassRequest;
use App\Http\Resources\AgentPassResource;
use App\Models\AgentPass;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Pass\PassService;
use App\Services\Pass\PassSettings;
use App\Services\Pass\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Admin: Propusk list / revenue, balance ledger, manual grant, wallet adjust, settings. */
class PassController extends ApiController
{
    public function __construct(
        private readonly PassService $passes,
        private readonly PassSettings $settings,
        private readonly WalletService $wallet,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'current', 'expired'])],
            'source' => ['nullable', Rule::in(['gateway', 'wallet', 'admin'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AgentPass::query()->with('user')->latest('id');

        if (isset($v['user_id'])) {
            $query->where('user_id', $v['user_id']);
        }
        if (isset($v['source'])) {
            $query->where('source', $v['source']);
        }
        // current = covering now, expired = ended, active = row status (not voided).
        if (($v['status'] ?? null) === 'current') {
            $query->where('starts_at', '<=', now())->where('expires_at', '>', now());
        } elseif (($v['status'] ?? null) === 'expired') {
            $query->where('expires_at', '<=', now());
        } elseif (($v['status'] ?? null) === 'active') {
            $query->where('status', 'active');
        }
        if (isset($v['from'])) {
            $query->where('created_at', '>=', $v['from']);
        }
        if (isset($v['to'])) {
            $query->where('created_at', '<=', $v['to'].' 23:59:59');
        }

        $paginator = $query->paginate($v['per_page'] ?? 20);

        return $this->success([
            'items' => AgentPassResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Revenue for a period: paid passes (admin grants are free and excluded)
     * plus per-otklik fees taken from agent balances; top-ups are cash in, shown apart.
     */
    public function summary(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = $v['from'] ?? now()->subDays(29)->toDateString();
        $to = $v['to'] ?? now()->toDateString();

        $base = AgentPass::query()
            ->where('price_tiyin', '>', 0)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to.' 23:59:59');

        $perDay = (clone $base)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count, SUM(price_tiyin) as sum_tiyin')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->day,
                'count' => (int) $r->count,
                'sum_som' => intdiv((int) $r->sum_tiyin, 100),
            ]);

        $total = (int) (clone $base)->sum('price_tiyin');

        $ledger = fn (WalletTransactionType $type) => WalletTransaction::query()
            ->where('type', $type)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to.' 23:59:59');

        // Fees are stored as debits (negative) — revenue is their absolute sum.
        $responseTiyin = -(int) $ledger(WalletTransactionType::ResponseFee)->sum('amount_tiyin');
        $topupTiyin = (int) $ledger(WalletTransactionType::Topup)->sum('amount_tiyin');

        $responsesPerDay = $ledger(WalletTransactionType::ResponseFee)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count, SUM(amount_tiyin) as sum_tiyin')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get()
            ->keyBy(fn ($r) => (string) $r->day);

        // One row per day with both revenue streams.
        $days = $perDay->keyBy('date');
        foreach ($responsesPerDay as $day => $r) {
            $days[$day] ??= ['date' => $day, 'count' => 0, 'sum_som' => 0];
        }
        $perDay = $days->map(fn (array $d) => $d + [
            'responses_count' => (int) ($responsesPerDay[$d['date']]->count ?? 0),
            'responses_sum_som' => intdiv(-(int) ($responsesPerDay[$d['date']]->sum_tiyin ?? 0), 100),
        ])->sortKeys()->values();

        return $this->success([
            'from' => $from,
            'to' => $to,
            'count' => (clone $base)->count(),
            'sum_tiyin' => $total,
            'sum_som' => intdiv($total, 100),
            'granted_count' => AgentPass::query()->where('source', 'admin')
                ->where('created_at', '>=', $from)->where('created_at', '<=', $to.' 23:59:59')->count(),
            'responses' => [
                'count' => $ledger(WalletTransactionType::ResponseFee)->count(),
                'sum_som' => intdiv($responseTiyin, 100),
            ],
            'topups' => [
                'count' => $ledger(WalletTransactionType::Topup)->count(),
                'sum_som' => intdiv($topupTiyin, 100),
            ],
            'revenue_som' => intdiv($total + $responseTiyin, 100),
            'per_day' => $perDay,
        ]);
    }

    /**
     * Balance ledger across agents: per-otklik fees, top-ups, passes paid from
     * the balance and manual adjustments. Signed amounts (debits negative).
     */
    public function transactions(Request $request): JsonResponse
    {
        $v = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(WalletTransactionType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WalletTransaction::query()->with('user.profile')->latest('id');

        if (isset($v['user_id'])) {
            $query->where('user_id', $v['user_id']);
        }
        if (isset($v['type'])) {
            $query->where('type', $v['type']);
        }
        if (isset($v['from'])) {
            $query->where('created_at', '>=', $v['from']);
        }
        if (isset($v['to'])) {
            $query->where('created_at', '<=', $v['to'].' 23:59:59');
        }

        $paginator = $query->paginate($v['per_page'] ?? 20);

        return $this->success([
            'items' => collect($paginator->items())->map(fn (WalletTransaction $t) => [
                'id' => $t->id,
                'user_id' => $t->user_id,
                'user' => $t->user ? [
                    'id' => $t->user->id,
                    'first_name' => $t->user->first_name,
                    'last_name' => $t->user->last_name,
                    'username' => $t->user->username,
                    'company_name' => $t->user->profile?->company_name,
                ] : null,
                'type' => $t->type->value,
                'amount_som' => intdiv($t->amount_tiyin, 100),
                // "otklik:{order}:{agent}:…" (older rows: "claim:…") — the order the fee was for.
                'order_id' => preg_match('/^(?:otklik|claim):(\d+):/', (string) $t->reference, $m) ? (int) $m[1] : null,
                'note' => $t->note,
                'created_by' => $t->created_by,
                'created_at' => $t->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function grant(GrantPassRequest $request, User $user): JsonResponse
    {
        $pass = $this->passes->grant($user, (int) $request->validated('hours'), $request->user(), $request->validated('reason'));

        return $this->success(new AgentPassResource($pass), 'Propusk granted', 201);
    }

    public function wallet(User $user): JsonResponse
    {
        return $this->success($this->walletPayload($user));
    }

    public function adjustWallet(AdjustWalletRequest $request, User $user): JsonResponse
    {
        $this->wallet->adjust($user, (int) $request->validated('amount_som') * 100, $request->validated('reason'), $request->user());

        return $this->success($this->walletPayload($user), 'Wallet adjusted');
    }

    public function settings(): JsonResponse
    {
        return $this->success($this->settings->all());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $v = $request->validate([
            'mode' => ['sometimes', Rule::enum(PassMode::class)],
            'price_som' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'hours' => ['sometimes', 'integer', 'min:1', 'max:8760'],
            'response_price_som' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            // null = unlimited.
            'max_active_claims' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $this->settings->update($v);

        return $this->success($this->settings->all(), 'Settings saved');
    }

    /** @return array<string, mixed> */
    private function walletPayload(User $user): array
    {
        return [
            'wallet_enabled' => (bool) config('passes.wallet_enabled'),
            'balance_som' => intdiv($this->wallet->balanceTiyin($user), 100),
            'transactions' => WalletTransaction::query()->where('user_id', $user->id)->latest('id')->limit(50)->get()
                ->map(fn (WalletTransaction $t) => [
                    'id' => $t->id,
                    'type' => $t->type->value,
                    'amount_som' => intdiv($t->amount_tiyin, 100),
                    'note' => $t->note,
                    'created_by' => $t->created_by,
                    'created_at' => $t->created_at?->toIso8601String(),
                ]),
        ];
    }
}
