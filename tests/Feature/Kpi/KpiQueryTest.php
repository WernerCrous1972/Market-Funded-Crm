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
