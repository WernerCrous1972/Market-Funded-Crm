<?php

declare(strict_types=1);

use App\Helpers\CountryHelper;
use App\Jobs\Metrics\RefreshPersonMetricsJob;
use App\Models\Activity;
use App\Models\Note;
use App\Models\Person;
use App\Models\PersonMetric;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// CountryHelper tests
// ─────────────────────────────────────────────────────────────────────────────

describe('CountryHelper', function () {

    it('converts full country name to ISO2', function () {
        expect(CountryHelper::toIso2('South Africa'))->toBe('ZA');
        expect(CountryHelper::toIso2('south africa'))->toBe('ZA');
        expect(CountryHelper::toIso2('SOUTH AFRICA'))->toBe('ZA');
        expect(CountryHelper::toIso2('United Kingdom'))->toBe('GB');
        expect(CountryHelper::toIso2('Nigeria'))->toBe('NG');
    });

    it('passes through 2-letter ISO codes unchanged', function () {
        expect(CountryHelper::toIso2('ZA'))->toBe('ZA');
        expect(CountryHelper::toIso2('GB'))->toBe('GB');
    });

    it('returns null for unknown country', function () {
        expect(CountryHelper::toIso2('Neverland'))->toBeNull();
        expect(CountryHelper::toIso2(null))->toBeNull();
        expect(CountryHelper::toIso2(''))->toBeNull();
    });

    it('generates flag emoji for known countries', function () {
        $flag = CountryHelper::toFlag('South Africa');
        expect($flag)->toBe('🇿🇦');

        $flag = CountryHelper::toFlag('Nigeria');
        expect($flag)->toBe('🇳🇬');
    });

    it('returns globe for unknown country flag', function () {
        expect(CountryHelper::toFlag('Neverland'))->toBe('🌍');
    });

    it('generates display string with flag and name', function () {
        $display = CountryHelper::display('South Africa');
        expect($display)->toContain('🇿🇦');
        expect($display)->toContain('South Africa');
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// RefreshPersonMetricsJob tests
// ─────────────────────────────────────────────────────────────────────────────

describe('RefreshPersonMetricsJob', function () {

    it('creates a person_metrics row for a person with no transactions', function () {
        $person = Person::factory()->create(['contact_type' => 'LEAD']);

        (new RefreshPersonMetricsJob())->handle();

        $metrics = PersonMetric::where('person_id', $person->id)->first();
        expect($metrics)->not->toBeNull();
        expect($metrics->total_deposits_cents)->toBe(0);
        expect($metrics->deposit_count)->toBe(0);
    });

    it('correctly sums EXTERNAL_DEPOSIT transactions', function () {
        $person = Person::factory()->create(['contact_type' => 'CLIENT']);

        Transaction::factory()->create([
            'person_id'    => $person->id,
            'category'     => 'EXTERNAL_DEPOSIT',
            'status'       => 'DONE',
            'amount_cents' => 100_000, // $1,000
            'type'         => 'DEPOSIT',
            'occurred_at'  => now()->subDays(10),
        ]);

        Transaction::factory()->create([
            'person_id'    => $person->id,
            'category'     => 'EXTERNAL_DEPOSIT',
            'status'       => 'DONE',
            'amount_cents' => 50_000, // $500
            'type'         => 'DEPOSIT',
            'occurred_at'  => now()->subDays(5),
        ]);

        (new RefreshPersonMetricsJob())->handle();

        $metrics = PersonMetric::where('person_id', $person->id)->first();
        expect($metrics->total_deposits_cents)->toBe(150_000);
        expect($metrics->deposit_count)->toBe(2);
    });

    it('excludes INTERNAL_TRANSFER and CHALLENGE_PURCHASE from real cashflow totals', function () {
        $person = Person::factory()->create(['contact_type' => 'CLIENT']);

        Transaction::factory()->create([
            'person_id'    => $person->id,
            'category'     => 'INTERNAL_TRANSFER',
            'status'       => 'DONE',
            'amount_cents' => 200_000,
            'type'         => 'DEPOSIT',
            'occurred_at'  => now(),
        ]);

        Transaction::factory()->create([
            'person_id'    => $person->id,
            'category'     => 'CHALLENGE_PURCHASE',
            'status'       => 'DONE',
            'amount_cents' => 14_990, // $149.90
            'type'         => 'WITHDRAWAL',
            'occurred_at'  => now(),
        ]);

        (new RefreshPersonMetricsJob())->handle();

        $metrics = PersonMetric::where('person_id', $person->id)->first();
        expect($metrics->total_deposits_cents)->toBe(0);   // INTERNAL_TRANSFER not counted
        expect($metrics->total_challenge_purchases_cents)->toBe(14_990);
    });

    it('calculates net deposits correctly', function () {
        $person = Person::factory()->create(['contact_type' => 'CLIENT']);

        Transaction::factory()->create([
            'person_id'    => $person->id,
            'category'     => 'EXTERNAL_DEPOSIT',
            'status'       => 'DONE',
            'amount_cents' => 300_000,
            'type'         => 'DEPOSIT',
            'occurred_at'  => now()->subDays(30),
        ]);

        Transaction::factory()->create([
            'person_id'    => $person->id,
            'category'     => 'EXTERNAL_WITHDRAWAL',
            'status'       => 'DONE',
            'amount_cents' => 100_000,
            'type'         => 'WITHDRAWAL',
            'occurred_at'  => now()->subDays(10),
        ]);

        (new RefreshPersonMetricsJob())->handle();

        $metrics = PersonMetric::where('person_id', $person->id)->first();
        expect($metrics->net_deposits_cents)->toBe(200_000);
    });

    it('refreshes a single person when personId is passed', function () {
        $person1 = Person::factory()->create();
        $person2 = Person::factory()->create();

        (new RefreshPersonMetricsJob($person1->id))->handle();

        expect(PersonMetric::where('person_id', $person1->id)->exists())->toBeTrue();
        expect(PersonMetric::where('person_id', $person2->id)->exists())->toBeFalse();
    });

    it('is idempotent — running twice does not duplicate rows', function () {
        $person = Person::factory()->create();

        (new RefreshPersonMetricsJob())->handle();
        (new RefreshPersonMetricsJob())->handle();

        expect(PersonMetric::where('person_id', $person->id)->count())->toBe(1);
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// Person model Phase 2 additions
// ─────────────────────────────────────────────────────────────────────────────

describe('Person model - Phase 2', function () {

    it('returns whatsapp link for e164 phone', function () {
        $person = Person::factory()->make(['phone_e164' => '+27681234567']);
        expect($person->whatsapp_link)->toBe('https://wa.me/27681234567');
    });

    it('returns null whatsapp link when phone is absent', function () {
        $person = Person::factory()->make(['phone_e164' => null]);
        expect($person->whatsapp_link)->toBeNull();
    });

    it('returns country display with flag', function () {
        $person = Person::factory()->make(['country' => 'South Africa']);
        expect($person->country_display)->toContain('🇿🇦');
    });

    it('reads pipelines from metrics when loaded', function () {
        $person  = Person::factory()->create();
        $metrics = PersonMetric::factory()->create([
            'person_id'   => $person->id,
            'has_markets' => true,
            'has_capital' => true,
            'has_academy' => false,
        ]);

        $person->setRelation('metrics', $metrics);

        expect($person->pipelines)->toContain('MFU_MARKETS');
        expect($person->pipelines)->toContain('MFU_CAPITAL');
        expect($person->pipelines)->not->toContain('MFU_ACADEMY');
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// Filament page smoke tests
// ─────────────────────────────────────────────────────────────────────────────

describe('Filament Phase 2 pages', function () {

    beforeEach(function () {
        $user = User::factory()->create(['role' => 'ADMIN', 'is_super_admin' => true]);
        $this->actingAs($user);
    });

    it('person list page loads', function () {
        $this->get('/admin/people')->assertOk();
    });

    it('person detail page loads with metrics and chart', function () {
        $person = Person::factory()->create();
        PersonMetric::factory()->create(['person_id' => $person->id]);

        $this->get("/admin/people/{$person->id}")->assertOk();
    });

    it('top ib partners report page loads', function () {
        $this->get('/admin/top-ib-partners-report')->assertOk();
    });

    it('lead conversion report page loads', function () {
        $this->get('/admin/lead-conversion-report')->assertOk();
    });

    it('deposits by pipeline report page loads', function () {
        $this->get('/admin/deposits-by-pipeline-report')->assertOk();
    });

});
