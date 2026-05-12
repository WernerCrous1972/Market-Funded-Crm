<?php

declare(strict_types=1);

namespace App\Services\Kpi;

use App\Models\Person;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for every KPI / dashboard metric.
 *
 * Every public method takes (KpiPeriod, KpiScope) and returns either:
 *   - a scalar (int cents for money, int count for counts, float for rates), or
 *   - a Collection for breakdowns (per-branch / per-agent rows).
 *
 * Money is returned as **cents** (bigint) to stay consistent with the rest
 * of the codebase. Callers format to USD when displaying.
 *
 * Rates are returned as 0.0–1.0 floats (multiply by 100 for percent display).
 *
 * All-time queries are cached for 1 hour via KpiPeriod::shouldCache(); other
 * windows aren't cached because they're tail-sensitive (new transactions
 * arrive throughout the day).
 */
class KpiQuery
{
    // ── Money-flow scalars ──────────────────────────────────────────────────

    public function depositsTotalCents(KpiPeriod $period, KpiScope $scope): int
    {
        return $this->sumTransactions('EXTERNAL_DEPOSIT', $period, $scope);
    }

    public function withdrawalsTotalCents(KpiPeriod $period, KpiScope $scope): int
    {
        return $this->sumTransactions('EXTERNAL_WITHDRAWAL', $period, $scope);
    }

    public function nettDepositsCents(KpiPeriod $period, KpiScope $scope): int
    {
        return $this->depositsTotalCents($period, $scope)
            - $this->withdrawalsTotalCents($period, $scope);
    }

    public function challengeSalesCents(KpiPeriod $period, KpiScope $scope): int
    {
        return $this->sumTransactions('CHALLENGE_PURCHASE', $period, $scope);
    }

    // ── Lead conversion ─────────────────────────────────────────────────────

    /**
     * Count of leads that converted to clients in the period.
     * Conversion = became_active_client_at falls inside the window.
     */
    public function conversionsCount(KpiPeriod $period, KpiScope $scope): int
    {
        $q = Person::query()->whereNotNull('became_active_client_at');

        if ($start = $period->start()) {
            $q->where('became_active_client_at', '>=', $start);
        }
        $q->where('became_active_client_at', '<=', $period->end());

        $this->applyScopeToPeople($q, $scope);

        return $q->count();
    }

    /**
     * Lead → Client conversion rate for the scope, over the period.
     *
     * Definition (per Werner 2026-05-12, "A"):
     *   numerator   = conversions in period
     *   denominator = persons EVER assigned to the scope who were leads at
     *                 the start of the period (i.e. eligible to convert).
     *                 For agent scope: people.account_manager_user_id = ?.
     *                 For branch scope: people.branch_id = ?.
     *                 For company scope: everyone with contact_type LEAD or CLIENT.
     *
     * The denominator INCLUDES already-converted clients because they were
     * once leads on this agent's book — excluding them would penalise high
     * performers (they'd have a shrinking denominator). We include
     * everyone who was a lead at the period start as the "pool".
     *
     * Returns 0.0 when the denominator is zero (empty book).
     */
    public function conversionRate(KpiPeriod $period, KpiScope $scope): float
    {
        $numerator = $this->conversionsCount($period, $scope);

        // Denominator: people in scope who were leads at the period start.
        // "Was a lead at period start" = either still a lead now, OR
        // became_active_client_at >= period start (i.e. they converted
        // DURING the period — so they were still a lead going in).
        $denomQ = Person::query()->where(function (Builder $q) use ($period) {
            $q->where('contact_type', 'LEAD');
            if ($start = $period->start()) {
                $q->orWhere('became_active_client_at', '>=', $start);
            } else {
                $q->orWhereNotNull('became_active_client_at');
            }
        });

        $this->applyScopeToPeople($denomQ, $scope);

        $denominator = $denomQ->count();
        if ($denominator === 0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    // ── Breakdowns ──────────────────────────────────────────────────────────

    /**
     * Money-metric breakdown across branches, sorted desc.
     *
     * $metric: 'deposits' | 'withdrawals' | 'nett' | 'challenge_sales'
     *
     * @return Collection<int, object{branch_id: string, branch_name: string, value_cents: int}>
     */
    public function perBranchBreakdown(string $metric, KpiPeriod $period): Collection
    {
        $category = $this->categoryFor($metric);

        if ($metric === 'nett') {
            $dep = $this->branchBreakdownRaw('EXTERNAL_DEPOSIT', $period)->keyBy('branch_id');
            $wit = $this->branchBreakdownRaw('EXTERNAL_WITHDRAWAL', $period)->keyBy('branch_id');
            $branchIds = $dep->keys()->merge($wit->keys())->unique();

            return $branchIds
                ->map(fn ($bid) => (object) [
                    'branch_id'   => $bid,
                    'branch_name' => $dep[$bid]->branch_name ?? $wit[$bid]->branch_name ?? '(no branch)',
                    'value_cents' => (int) (($dep[$bid]->value_cents ?? 0) - ($wit[$bid]->value_cents ?? 0)),
                ])
                ->sortByDesc('value_cents')
                ->values();
        }

        return $this->branchBreakdownRaw($category, $period);
    }

    /**
     * Per-account-manager leaderboard rows. One row per user who has
     * any people assigned. When $onlyAgentId is set, restricts to that
     * user's row (used for agent-view scoping).
     *
     * Each row carries every leaderboard metric so widgets can sort
     * across columns without re-querying.
     *
     * @return Collection<int, object{
     *   user_id: string,
     *   user_name: string,
     *   conversion_rate: float,
     *   conversions: int,
     *   challenge_sales_cents: int,
     *   deposits_cents: int,
     *   nett_deposits_cents: int
     * }>
     */
    public function leaderboard(KpiPeriod $period, ?string $onlyAgentId = null): Collection
    {
        $agentQ = User::query()
            ->whereIn('role', ['SALES_AGENT', 'SALES_MANAGER', 'ADMIN'])
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('people')
                    ->whereColumn('people.account_manager_user_id', 'users.id');
            });

        if ($onlyAgentId !== null) {
            $agentQ->where('id', $onlyAgentId);
        }

        return $agentQ->get(['id', 'name'])
            ->map(function (User $u) use ($period) {
                $scope = KpiScope::agent($u->id);
                $deposits = $this->depositsTotalCents($period, $scope);
                $withdrawals = $this->withdrawalsTotalCents($period, $scope);
                return (object) [
                    'user_id'               => $u->id,
                    'user_name'             => $u->name,
                    'conversion_rate'       => $this->conversionRate($period, $scope),
                    'conversions'           => $this->conversionsCount($period, $scope),
                    'challenge_sales_cents' => $this->challengeSalesCents($period, $scope),
                    'deposits_cents'        => $deposits,
                    'nett_deposits_cents'   => $deposits - $withdrawals,
                ];
            })
            ->sortByDesc('nett_deposits_cents')
            ->values();
    }

    /**
     * Average across all agents — for the agent-view "vs company average"
     * comparison. Excludes agents with zero people assigned (they'd drag
     * the mean down to noise).
     */
    public function companyAverages(KpiPeriod $period): object
    {
        $rows = $this->leaderboard($period);
        $n = $rows->count();
        if ($n === 0) {
            return (object) [
                'conversion_rate'       => 0.0,
                'challenge_sales_cents' => 0,
                'deposits_cents'        => 0,
                'nett_deposits_cents'   => 0,
                'agent_count'           => 0,
            ];
        }
        return (object) [
            'conversion_rate'       => $rows->avg('conversion_rate'),
            'challenge_sales_cents' => (int) $rows->avg('challenge_sales_cents'),
            'deposits_cents'        => (int) $rows->avg('deposits_cents'),
            'nett_deposits_cents'   => (int) $rows->avg('nett_deposits_cents'),
            'agent_count'           => $n,
        ];
    }

    // ── Internal helpers ────────────────────────────────────────────────────

    private function sumTransactions(string $category, KpiPeriod $period, KpiScope $scope): int
    {
        $compute = function () use ($category, $period, $scope) {
            $q = Transaction::query()
                ->where('category', $category)
                ->where('status', 'DONE');

            if ($start = $period->start()) {
                $q->where('occurred_at', '>=', $start);
            }
            $q->where('occurred_at', '<=', $period->end());

            $this->applyScopeToTransactions($q, $scope);

            return (int) ($q->sum('amount_cents') ?? 0);
        };

        if ($period->shouldCache()) {
            return Cache::remember(
                'kpi:sum:' . $category . ':' . $scope->type . ':' . ($scope->id ?? 'co') . ':' . $period->cacheKey(),
                3600,
                $compute,
            );
        }

        return $compute();
    }

    /**
     * @return Collection<int, object{branch_id: string, branch_name: string, value_cents: int}>
     */
    private function branchBreakdownRaw(string $category, KpiPeriod $period): Collection
    {
        $q = DB::table('transactions')
            ->join('people', 'people.id', '=', 'transactions.person_id')
            ->leftJoin('branches', 'branches.id', '=', 'people.branch_id')
            ->where('transactions.category', $category)
            ->where('transactions.status', 'DONE')
            ->whereNotNull('people.branch_id')
            ->select(
                'people.branch_id as branch_id',
                DB::raw('COALESCE(branches.name, \'(unnamed)\') as branch_name'),
                DB::raw('SUM(transactions.amount_cents) as value_cents'),
            )
            ->groupBy('people.branch_id', 'branches.name')
            ->orderByDesc('value_cents');

        if ($start = $period->start()) {
            $q->where('transactions.occurred_at', '>=', $start);
        }
        $q->where('transactions.occurred_at', '<=', $period->end());

        return $q->get()->map(fn ($r) => (object) [
            'branch_id'   => $r->branch_id,
            'branch_name' => $r->branch_name,
            'value_cents' => (int) $r->value_cents,
        ]);
    }

    private function applyScopeToTransactions(Builder $q, KpiScope $scope): void
    {
        match ($scope->type) {
            'company' => null,
            'branch'  => $q->whereHas('person', fn ($p) => $p->where('branch_id', $scope->id)),
            'agent'   => $q->whereHas('person', fn ($p) => $p->where('account_manager_user_id', $scope->id)),
        };
    }

    private function applyScopeToPeople(Builder $q, KpiScope $scope): void
    {
        match ($scope->type) {
            'company' => null,
            'branch'  => $q->where('branch_id', $scope->id),
            'agent'   => $q->where('account_manager_user_id', $scope->id),
        };
    }

    private function categoryFor(string $metric): string
    {
        return match ($metric) {
            'deposits'        => 'EXTERNAL_DEPOSIT',
            'withdrawals'     => 'EXTERNAL_WITHDRAWAL',
            'challenge_sales' => 'CHALLENGE_PURCHASE',
            'nett'            => 'NETT', // sentinel — caller handles
            default           => throw new \InvalidArgumentException("Unknown metric: {$metric}"),
        };
    }
}
