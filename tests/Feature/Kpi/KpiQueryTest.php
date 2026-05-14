<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Person;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Kpi\KpiPeriod;
use App\Services\Kpi\KpiQuery;
use App\Services\Kpi\KpiScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->kpi = app(KpiQuery::class);

    // Two agents, two branches, deterministic IDs for asserts
    $this->agentA = User::factory()->create(['role' => 'SALES_AGENT', 'name' => 'Agent A']);
    $this->agentB = User::factory()->create(['role' => 'SALES_AGENT', 'name' => 'Agent B']);

    $this->branchA = Branch::factory()->create(['name' => 'Branch A']);
    $this->branchB = Branch::factory()->create(['name' => 'Branch B']);
});

/**
 * Helper to drop a DONE transaction at a specific date, attached to a specific
 * person.
 */
function txn(string $personId, string $category, int $cents, string $date): Transaction
{
    return Transaction::create([
        'mtr_transaction_uuid' => 'test-' . uniqid(),
        'person_id'            => $personId,
        'trading_account_id'   => null,
        'type'                 => $category === 'EXTERNAL_WITHDRAWAL' ? 'WITHDRAWAL' : 'DEPOSIT',
        'status'               => 'DONE',
        'category'             => $category,
        'amount_cents'         => $cents,
        'currency'             => 'USD',
        'occurred_at'          => CarbonImmutable::parse($date),
        'synced_at'            => CarbonImmutable::now(),
    ]);
}

it('sums deposits, withdrawals, nett, and challenge sales across the company over MTD', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $p = Person::factory()->create([
        'branch_id'               => $this->branchA->id,
        'account_manager_user_id' => $this->agentA->id,
    ]);

    txn($p->id, 'EXTERNAL_DEPOSIT',    100_00, '2026-05-05'); // in window
    txn($p->id, 'EXTERNAL_DEPOSIT',    200_00, '2026-04-30'); // before window
    txn($p->id, 'EXTERNAL_WITHDRAWAL',  40_00, '2026-05-10');
    txn($p->id, 'CHALLENGE_PURCHASE',   50_00, '2026-05-12');

    $period = KpiPeriod::default();
    $scope  = KpiScope::company();

    expect($this->kpi->depositsTotalCents($period, $scope))->toBe(100_00);
    expect($this->kpi->withdrawalsTotalCents($period, $scope))->toBe(40_00);
    expect($this->kpi->nettDepositsCents($period, $scope))->toBe(60_00);
    expect($this->kpi->challengeSalesCents($period, $scope))->toBe(50_00);
});

it('respects scope: branch totals exclude other branches', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $pA = Person::factory()->create(['branch_id' => $this->branchA->id]);
    $pB = Person::factory()->create(['branch_id' => $this->branchB->id]);

    txn($pA->id, 'EXTERNAL_DEPOSIT', 300_00, '2026-05-10');
    txn($pB->id, 'EXTERNAL_DEPOSIT', 700_00, '2026-05-10');

    $period = KpiPeriod::default();

    expect($this->kpi->depositsTotalCents($period, KpiScope::branch($this->branchA->id)))->toBe(300_00);
    expect($this->kpi->depositsTotalCents($period, KpiScope::branch($this->branchB->id)))->toBe(700_00);
    expect($this->kpi->depositsTotalCents($period, KpiScope::company()))->toBe(1000_00);
});

it('respects scope: agent totals filter on account_manager_user_id', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $pA = Person::factory()->create(['account_manager_user_id' => $this->agentA->id]);
    $pB = Person::factory()->create(['account_manager_user_id' => $this->agentB->id]);

    txn($pA->id, 'EXTERNAL_DEPOSIT', 500_00, '2026-05-10');
    txn($pB->id, 'EXTERNAL_DEPOSIT', 900_00, '2026-05-10');

    $period = KpiPeriod::default();

    expect($this->kpi->depositsTotalCents($period, KpiScope::agent($this->agentA->id)))->toBe(500_00);
    expect($this->kpi->depositsTotalCents($period, KpiScope::agent($this->agentB->id)))->toBe(900_00);
});

it('only counts DONE transactions', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));
    $p = Person::factory()->create();

    txn($p->id, 'EXTERNAL_DEPOSIT', 100_00, '2026-05-10');
    Transaction::create([
        'mtr_transaction_uuid' => 'test-pending',
        'person_id'            => $p->id,
        'trading_account_id'   => null,
        'type'                 => 'DEPOSIT',
        'status'               => 'PENDING',
        'category'             => 'EXTERNAL_DEPOSIT',
        'amount_cents'         => 999_00,
        'currency'             => 'USD',
        'occurred_at'          => CarbonImmutable::parse('2026-05-10'),
        'synced_at'            => CarbonImmutable::now(),
    ]);

    expect($this->kpi->depositsTotalCents(KpiPeriod::default(), KpiScope::company()))->toBe(100_00);
});

it('counts only conversions whose became_active_client_at falls in the period', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    Person::factory()->create([
        'contact_type'              => 'CLIENT',
        'became_active_client_at'   => CarbonImmutable::parse('2026-05-10'),
        'account_manager_user_id'   => $this->agentA->id,
    ]);
    Person::factory()->create([
        'contact_type'              => 'CLIENT',
        'became_active_client_at'   => CarbonImmutable::parse('2026-04-20'),  // before window
        'account_manager_user_id'   => $this->agentA->id,
    ]);

    expect($this->kpi->conversionsCount(KpiPeriod::default(), KpiScope::agent($this->agentA->id)))
        ->toBe(1);
});

it('computes conversion rate using the inclusive denominator (leads + period-converted clients)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    // 3 leads still leads at period start, 1 converted in period, 1 converted before period
    Person::factory()->count(3)->create([
        'contact_type'              => 'LEAD',
        'account_manager_user_id'   => $this->agentA->id,
    ]);
    Person::factory()->create([
        'contact_type'              => 'CLIENT',
        'became_active_client_at'   => CarbonImmutable::parse('2026-05-08'),
        'account_manager_user_id'   => $this->agentA->id,
    ]);
    Person::factory()->create([
        'contact_type'              => 'CLIENT',
        'became_active_client_at'   => CarbonImmutable::parse('2026-04-10'),  // not in pool
        'account_manager_user_id'   => $this->agentA->id,
    ]);

    // Eligible pool at period start: 3 leads + 1 client that converted IN the period = 4
    // Conversions in period: 1
    // Rate: 1 / 4 = 0.25
    $rate = $this->kpi->conversionRate(KpiPeriod::default(), KpiScope::agent($this->agentA->id));
    expect($rate)->toEqualWithDelta(0.25, 0.001);
});

it('returns 0.0 conversion rate when the denominator is empty', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $rate = $this->kpi->conversionRate(KpiPeriod::default(), KpiScope::agent($this->agentA->id));
    expect($rate)->toBe(0.0);
});

it('produces per-branch breakdown sorted desc with NETT correctly netted', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $pA = Person::factory()->create(['branch_id' => $this->branchA->id]);
    $pB = Person::factory()->create(['branch_id' => $this->branchB->id]);

    txn($pA->id, 'EXTERNAL_DEPOSIT',    200_00, '2026-05-05');
    txn($pA->id, 'EXTERNAL_WITHDRAWAL',  50_00, '2026-05-06');
    txn($pB->id, 'EXTERNAL_DEPOSIT',    300_00, '2026-05-07');
    txn($pB->id, 'EXTERNAL_WITHDRAWAL', 100_00, '2026-05-08');

    $rows = $this->kpi->perBranchBreakdown('nett', KpiPeriod::default());

    expect($rows->count())->toBe(2);
    expect($rows->first()->branch_name)->toBe('Branch B'); // 200 nett, higher
    expect($rows->first()->value_cents)->toBe(200_00);
    expect($rows->last()->branch_name)->toBe('Branch A');  // 150 nett
    expect($rows->last()->value_cents)->toBe(150_00);
});

it('builds a leaderboard with one row per agent who has any assigned people', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $pA = Person::factory()->create(['account_manager_user_id' => $this->agentA->id]);
    $pB = Person::factory()->create(['account_manager_user_id' => $this->agentB->id]);

    txn($pA->id, 'EXTERNAL_DEPOSIT', 100_00, '2026-05-10');
    txn($pB->id, 'EXTERNAL_DEPOSIT', 500_00, '2026-05-10');

    $rows = $this->kpi->leaderboard(KpiPeriod::default());

    expect($rows->count())->toBe(2);
    expect($rows->first()->user_name)->toBe('Agent B');  // higher NETT — default sort
    expect($rows->first()->nett_deposits_cents)->toBe(500_00);
    expect($rows->last()->user_name)->toBe('Agent A');
});

it('leaderboard with onlyAgentId returns just that agent\'s row', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    Person::factory()->create(['account_manager_user_id' => $this->agentA->id]);
    Person::factory()->create(['account_manager_user_id' => $this->agentB->id]);

    $rows = $this->kpi->leaderboard(KpiPeriod::default(), $this->agentA->id);

    expect($rows->count())->toBe(1);
    expect($rows->first()->user_id)->toBe($this->agentA->id);
});

it('historical attribution: transaction.account_manager_user_id wins over person.account_manager_user_id', function () {
    // Scenario: a person is currently owned by Agent B (reassignment),
    // but the deposit was made when Agent A owned them. The deposit
    // should land in Agent A's totals — not Agent B's.
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $p = Person::factory()->create([
        'account_manager_user_id' => $this->agentB->id,  // CURRENT owner
    ]);

    txn($p->id, 'EXTERNAL_DEPOSIT', 100_00, '2026-05-10')
        ->update(['account_manager_user_id' => $this->agentA->id]);  // historical

    $period = KpiPeriod::default();

    // Agent A (historical owner) gets the credit
    expect($this->kpi->depositsTotalCents($period, KpiScope::agent($this->agentA->id)))->toBe(100_00);
    // Agent B (current owner) gets nothing
    expect($this->kpi->depositsTotalCents($period, KpiScope::agent($this->agentB->id)))->toBe(0);
});

it('historical attribution: NULL on transaction falls back to current owner (legacy data)', function () {
    // Older transactions synced before the column existed have NULL
    // account_manager_user_id. They must still attribute correctly via
    // people.account_manager_user_id until the backfill runs.
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $p = Person::factory()->create(['account_manager_user_id' => $this->agentA->id]);

    txn($p->id, 'EXTERNAL_DEPOSIT', 200_00, '2026-05-10');
    // Note: txn() does NOT populate account_manager_user_id — that's
    // exactly the legacy NULL case we're testing.

    expect($this->kpi->depositsTotalCents(KpiPeriod::default(), KpiScope::agent($this->agentA->id)))
        ->toBe(200_00);
});

it('historical attribution: same person with two managers split across the period', function () {
    // Re-creates the Ntahli situation from production: same email,
    // multiple deposits, manager reassignment mid-period.
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $p = Person::factory()->create([
        'account_manager_user_id' => $this->agentB->id,  // currently owned by B
    ]);

    // First two deposits while owned by A
    txn($p->id, 'EXTERNAL_DEPOSIT', 50_00, '2026-05-04')
        ->update(['account_manager_user_id' => $this->agentA->id]);
    txn($p->id, 'EXTERNAL_DEPOSIT', 30_00, '2026-05-06')
        ->update(['account_manager_user_id' => $this->agentA->id]);

    // Then reassigned. Two more deposits under B.
    txn($p->id, 'EXTERNAL_DEPOSIT', 10_00, '2026-05-08')
        ->update(['account_manager_user_id' => $this->agentB->id]);
    txn($p->id, 'EXTERNAL_DEPOSIT', 20_00, '2026-05-10')
        ->update(['account_manager_user_id' => $this->agentB->id]);

    $period = KpiPeriod::default();

    expect($this->kpi->depositsTotalCents($period, KpiScope::agent($this->agentA->id)))->toBe(80_00);
    expect($this->kpi->depositsTotalCents($period, KpiScope::agent($this->agentB->id)))->toBe(30_00);
    // Company total is the sum
    expect($this->kpi->depositsTotalCents($period, KpiScope::company()))->toBe(110_00);
});

it('historical attribution: per-agent breakdown uses transaction column when populated', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $p = Person::factory()->create([
        'account_manager_user_id' => $this->agentB->id,  // current
    ]);

    txn($p->id, 'EXTERNAL_DEPOSIT', 100_00, '2026-05-10')
        ->update(['account_manager_user_id' => $this->agentA->id]);

    $rows = $this->kpi->perAgentBreakdown('deposits', 'value', KpiPeriod::default());

    expect($rows->count())->toBe(1);
    expect($rows->first()->user_name)->toBe('Agent A');  // historical wins
    expect($rows->first()->value)->toBe(100_00);
});

it('per-agent breakdown returns deposits value sorted desc, agents with no transactions excluded', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $pA = Person::factory()->create(['account_manager_user_id' => $this->agentA->id]);
    $pB = Person::factory()->create(['account_manager_user_id' => $this->agentB->id]);

    txn($pA->id, 'EXTERNAL_DEPOSIT', 100_00, '2026-05-05');
    txn($pA->id, 'EXTERNAL_DEPOSIT',  50_00, '2026-05-06');
    txn($pB->id, 'EXTERNAL_DEPOSIT', 500_00, '2026-05-07');

    $rows = $this->kpi->perAgentBreakdown('deposits', 'value', KpiPeriod::default());

    expect($rows->count())->toBe(2);
    expect($rows->first()->user_name)->toBe('Agent B'); // 500_00 — higher
    expect($rows->first()->value)->toBe(500_00);
    expect($rows->last()->user_name)->toBe('Agent A');
    expect($rows->last()->value)->toBe(150_00);
});

it('per-agent breakdown count mode returns transaction counts not amounts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $pA = Person::factory()->create(['account_manager_user_id' => $this->agentA->id]);
    $pB = Person::factory()->create(['account_manager_user_id' => $this->agentB->id]);

    // 3 deposits for A, 1 for B — A wins on count, B wins on value
    txn($pA->id, 'EXTERNAL_DEPOSIT', 50_00, '2026-05-05');
    txn($pA->id, 'EXTERNAL_DEPOSIT', 50_00, '2026-05-06');
    txn($pA->id, 'EXTERNAL_DEPOSIT', 50_00, '2026-05-07');
    txn($pB->id, 'EXTERNAL_DEPOSIT', 999_00, '2026-05-08');

    $rows = $this->kpi->perAgentBreakdown('deposits', 'count', KpiPeriod::default());

    expect($rows->first()->user_name)->toBe('Agent A');
    expect($rows->first()->value)->toBe(3);
    expect($rows->last()->user_name)->toBe('Agent B');
    expect($rows->last()->value)->toBe(1);
});

it('per-agent breakdown supports challenge_sales metric', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $p = Person::factory()->create(['account_manager_user_id' => $this->agentA->id]);
    txn($p->id, 'CHALLENGE_PURCHASE', 200_00, '2026-05-10');
    txn($p->id, 'CHALLENGE_PURCHASE', 100_00, '2026-05-11');
    // Unrelated deposit shouldn't leak into challenge sales
    txn($p->id, 'EXTERNAL_DEPOSIT', 999_00, '2026-05-12');

    $rows = $this->kpi->perAgentBreakdown('challenge_sales', 'value', KpiPeriod::default());
    expect($rows->first()->value)->toBe(300_00);

    $countRows = $this->kpi->perAgentBreakdown('challenge_sales', 'count', KpiPeriod::default());
    expect($countRows->first()->value)->toBe(2);
});

it('per-agent breakdown rejects invalid metric or mode', function () {
    expect(fn () => $this->kpi->perAgentBreakdown('bogus', 'value', KpiPeriod::default()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->kpi->perAgentBreakdown('deposits', 'bogus', KpiPeriod::default()))
        ->toThrow(InvalidArgumentException::class);
});

it('honours a custom date range', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $p = Person::factory()->create();
    txn($p->id, 'EXTERNAL_DEPOSIT', 500_00, '2026-04-10'); // in window
    txn($p->id, 'EXTERNAL_DEPOSIT', 700_00, '2026-04-22'); // in window
    txn($p->id, 'EXTERNAL_DEPOSIT', 999_00, '2026-05-01'); // OUT of window

    $period = KpiPeriod::custom('2026-04-01', '2026-04-30');
    expect($this->kpi->depositsTotalCents($period, KpiScope::company()))->toBe(1200_00);
});

it('fromFilters returns the default period when filters are empty or malformed', function () {
    expect(KpiPeriod::fromFilters([])->key())->toBe(KpiPeriod::DEFAULT);
    expect(KpiPeriod::fromFilters(['period' => 'custom'])->key())->toBe(KpiPeriod::DEFAULT); // dates missing
    expect(KpiPeriod::fromFilters(['period' => 'custom', 'custom_start' => 'not-a-date', 'custom_end' => '2026-04-30'])->key())->toBe(KpiPeriod::DEFAULT);
});

it('fromFilters builds a usable custom period when both dates are present', function () {
    $p = KpiPeriod::fromFilters([
        'period'       => 'custom',
        'custom_start' => '2026-04-01',
        'custom_end'   => '2026-04-30',
    ]);
    expect($p->key())->toBe('custom');
    expect($p->start()?->toDateString())->toBe('2026-04-01');
    expect($p->end()->toDateString())->toBe('2026-04-30');
});

it('rejects custom periods where end is before start', function () {
    KpiPeriod::custom('2026-04-30', '2026-04-01');
})->throws(InvalidArgumentException::class);

it('counts transactions independently of value totals', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $p = Person::factory()->create();
    txn($p->id, 'EXTERNAL_DEPOSIT',  100_00, '2026-05-01');
    txn($p->id, 'EXTERNAL_DEPOSIT',  200_00, '2026-05-02');
    txn($p->id, 'EXTERNAL_DEPOSIT',  500_00, '2026-05-03');
    txn($p->id, 'EXTERNAL_WITHDRAWAL', 50_00, '2026-05-04');

    $period = KpiPeriod::default();
    $scope  = KpiScope::company();

    expect($this->kpi->depositsCount($period, $scope))->toBe(3);
    expect($this->kpi->withdrawalsCount($period, $scope))->toBe(1);
    expect($this->kpi->challengeSalesCount($period, $scope))->toBe(0);
});

it('builds a daily trend series with one bucket per day in the window', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $p = Person::factory()->create();
    txn($p->id, 'EXTERNAL_DEPOSIT', 100_00, '2026-05-05');
    txn($p->id, 'EXTERNAL_DEPOSIT', 200_00, '2026-05-10');

    $series = $this->kpi->dailyTrend('deposits', KpiPeriod::default(), KpiScope::company());

    // May 1 through May 15 inclusive = 15 buckets
    expect($series->count())->toBe(15);
    // Verify a specific known-good bucket
    $may10 = $series->firstWhere('label', 'May-10');
    expect($may10?->value_cents)->toBe(200_00);
    // Empty days are zero, not null
    $may2 = $series->firstWhere('label', 'May-2');
    expect($may2?->value_cents)->toBe(0);
});

it('branchHealthGrid returns one row per branch with people attached', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $pA = Person::factory()->create([
        'branch_id'      => $this->branchA->id,
        'mtr_created_at' => CarbonImmutable::parse('2026-05-05'),
        'contact_type'   => 'LEAD',
    ]);
    $pB = Person::factory()->create([
        'branch_id'               => $this->branchB->id,
        'contact_type'            => 'CLIENT',
        'became_active_client_at' => CarbonImmutable::parse('2026-05-08'),
    ]);
    txn($pA->id, 'CHALLENGE_PURCHASE', 100_00, '2026-05-07');
    txn($pB->id, 'EXTERNAL_DEPOSIT',   500_00, '2026-05-09');

    $rows = $this->kpi->branchHealthGrid(KpiPeriod::default());

    expect($rows->count())->toBe(2);
    $a = $rows->firstWhere('branch_name', 'Branch A');
    expect($a->new_leads)->toBe(1);
    expect($a->challenge_sales_cents)->toBe(100_00);

    $b = $rows->firstWhere('branch_name', 'Branch B');
    expect($b->new_clients)->toBe(1);
    expect($b->nett_cents)->toBe(500_00);
});

it('computes company averages across all agents who have any assigned people', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    $pA = Person::factory()->create(['account_manager_user_id' => $this->agentA->id]);
    $pB = Person::factory()->create(['account_manager_user_id' => $this->agentB->id]);

    txn($pA->id, 'EXTERNAL_DEPOSIT', 100_00, '2026-05-10');
    txn($pB->id, 'EXTERNAL_DEPOSIT', 500_00, '2026-05-10');

    $avg = $this->kpi->companyAverages(KpiPeriod::default());

    expect($avg->agent_count)->toBe(2);
    expect($avg->deposits_cents)->toBe(300_00);    // (100 + 500) / 2
});
