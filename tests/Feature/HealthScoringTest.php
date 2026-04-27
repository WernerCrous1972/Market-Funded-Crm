<?php

declare(strict_types=1);

use App\Jobs\Metrics\CalculateHealthScoresJob;
use App\Models\Person;
use App\Models\PersonMetric;
use App\Services\Health\HealthScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('HealthScorer', function () {

    it('starts at base score of 50', function () {
        $scorer  = new HealthScorer();
        $metrics = new PersonMetric([
            'days_since_last_login'    => 5,   // +15
            'days_since_last_deposit'  => 20,  // +20
            'total_deposits_cents'     => 100_000,
            'total_withdrawals_cents'  => 10_000,
            'deposits_mtd_cents'       => 20_000,
            'first_deposit_at'         => now()->subMonths(2),
        ]);

        $result = $scorer->score($metrics);

        expect($result['score'])->toBeGreaterThan(50);
        expect($result['grade'])->toBeIn(['A', 'B', 'C']);
    });

    it('gives maximum score for an ideal client', function () {
        $scorer  = new HealthScorer();
        $metrics = new PersonMetric([
            'days_since_last_login'    => 1,       // +15
            'days_since_last_deposit'  => 5,       // +20
            'total_deposits_cents'     => 500_000, // ratio 50x → +15
            'total_withdrawals_cents'  => 10_000,
            'deposits_mtd_cents'       => 100_000, // well above average → +10
            'first_deposit_at'         => now()->subMonths(3),
        ]);

        $result = $scorer->score($metrics);
        // Base 50 + 15 + 20 + 15 + 10 = 110, clamped to 100
        expect($result['score'])->toBe(100);
        expect($result['grade'])->toBe('A');
    });

    it('gives minimum score for a dormant client', function () {
        $scorer  = new HealthScorer();
        $metrics = new PersonMetric([
            'days_since_last_login'    => 60,  // -15
            'days_since_last_deposit'  => 90,  // -20
            'total_deposits_cents'     => 50_000,
            'total_withdrawals_cents'  => 80_000, // ratio < 1 → -15
            'deposits_mtd_cents'       => 0,   // -10
            'first_deposit_at'         => now()->subMonths(6),
        ]);

        $result = $scorer->score($metrics);
        // Base 50 - 15 - 20 - 15 - 10 = -10, clamped to 0
        expect($result['score'])->toBe(0);
        expect($result['grade'])->toBe('F');
    });

    it('handles null login (never logged in)', function () {
        $scorer  = new HealthScorer();
        $metrics = new PersonMetric([
            'days_since_last_login'    => null, // -15
            'days_since_last_deposit'  => 10,   // +20
            'total_deposits_cents'     => 100_000,
            'total_withdrawals_cents'  => 0,
            'deposits_mtd_cents'       => 20_000,
            'first_deposit_at'         => now()->subMonths(1),
        ]);

        $result = $scorer->score($metrics);
        expect($result['breakdown']['login_recency']['points'])->toBe(-15);
        expect($result['breakdown']['login_recency']['detail'])->toContain('Never');
    });

    it('handles client who never deposited', function () {
        $scorer  = new HealthScorer();
        $metrics = new PersonMetric([
            'days_since_last_login'    => 3,
            'days_since_last_deposit'  => null, // -20
            'total_deposits_cents'     => 0,    // ratio → -15
            'total_withdrawals_cents'  => 0,
            'deposits_mtd_cents'       => 0,    // trend → -10
            'first_deposit_at'         => null,
        ]);

        $result = $scorer->score($metrics);
        expect($result['breakdown']['deposit_recency']['points'])->toBe(-20);
        expect($result['breakdown']['deposit_withdrawal_ratio']['points'])->toBe(-15);
        expect($result['breakdown']['deposit_trend']['points'])->toBe(-10);
    });

    it('correctly grades scores', function () {
        $scorer = new HealthScorer();
        expect($scorer->grade(85))->toBe('A');
        expect($scorer->grade(70))->toBe('B');
        expect($scorer->grade(55))->toBe('C');
        expect($scorer->grade(40))->toBe('D');
        expect($scorer->grade(20))->toBe('F');
        expect($scorer->grade(0))->toBe('F');
        expect($scorer->grade(100))->toBe('A');
    });

    it('includes breakdown for all active factors', function () {
        $scorer  = new HealthScorer();
        $metrics = new PersonMetric([
            'days_since_last_login'    => 5,
            'days_since_last_deposit'  => 10,
            'total_deposits_cents'     => 100_000,
            'total_withdrawals_cents'  => 20_000,
            'deposits_mtd_cents'       => 15_000,
            'first_deposit_at'         => now()->subMonths(2),
        ]);

        $result = $scorer->score($metrics);
        expect($result['breakdown'])->toHaveKeys([
            'login_recency',
            'deposit_recency',
            'deposit_withdrawal_ratio',
            'deposit_trend',
            'equity_change',   // pending
            'open_positions',  // pending
        ]);
    });

    it('marks Phase 4 factors as pending', function () {
        $scorer  = new HealthScorer();
        $metrics = new PersonMetric([
            'days_since_last_login'   => 1,
            'days_since_last_deposit' => 1,
            'total_deposits_cents'    => 100_000,
            'total_withdrawals_cents' => 0,
            'deposits_mtd_cents'      => 10_000,
            'first_deposit_at'        => now()->subMonth(),
        ]);

        $result = $scorer->score($metrics);
        expect($result['breakdown']['equity_change']['pending'])->toBeTrue();
        expect($result['breakdown']['open_positions']['pending'])->toBeTrue();
        expect($result['breakdown']['equity_change']['points'])->toBe(0);
    });

});

describe('CalculateHealthScoresJob', function () {

    it('only scores CLIENT records', function () {
        $client = Person::factory()->create(['contact_type' => 'CLIENT']);
        $lead   = Person::factory()->create(['contact_type' => 'LEAD']);

        PersonMetric::factory()->create(['person_id' => $client->id]);
        PersonMetric::factory()->create(['person_id' => $lead->id]);

        (new CalculateHealthScoresJob())->handle(new HealthScorer());

        expect(PersonMetric::where('person_id', $client->id)->value('health_score'))->not->toBeNull();
        expect(PersonMetric::where('person_id', $lead->id)->value('health_score'))->toBeNull();
    });

    it('scores a single person when personId is passed', function () {
        $client1 = Person::factory()->create(['contact_type' => 'CLIENT']);
        $client2 = Person::factory()->create(['contact_type' => 'CLIENT']);

        PersonMetric::factory()->create(['person_id' => $client1->id]);
        PersonMetric::factory()->create(['person_id' => $client2->id]);

        (new CalculateHealthScoresJob($client1->id))->handle(new HealthScorer());

        expect(PersonMetric::where('person_id', $client1->id)->value('health_score'))->not->toBeNull();
        expect(PersonMetric::where('person_id', $client2->id)->value('health_score'))->toBeNull();
    });

    it('writes grade and breakdown alongside score', function () {
        $client  = Person::factory()->create(['contact_type' => 'CLIENT']);
        PersonMetric::factory()->create(['person_id' => $client->id]);

        (new CalculateHealthScoresJob($client->id))->handle(new HealthScorer());

        $metrics = PersonMetric::where('person_id', $client->id)->first();
        expect($metrics->health_grade)->toBeIn(['A', 'B', 'C', 'D', 'F']);
        expect($metrics->health_score_breakdown)->toBeArray();
        expect($metrics->health_score_calculated_at)->not->toBeNull();
    });

});
