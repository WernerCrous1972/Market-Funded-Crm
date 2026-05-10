<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\PersonMetric;
use App\Models\Transaction;

describe('Henry API', function () {

    beforeEach(function () {
        config()->set('henry.api_token', 'test-secret-token');
    });

    it('rejects requests without a bearer token', function () {
        $response = $this->getJson('/api/henry/health');
        $response->assertStatus(401);
    });

    it('rejects requests with the wrong bearer token', function () {
        $response = $this->withHeader('Authorization', 'Bearer wrong')
            ->getJson('/api/henry/health');
        $response->assertStatus(401);
    });

    it('returns 503 when no token is configured', function () {
        config()->set('henry.api_token', '');
        $response = $this->withHeader('Authorization', 'Bearer anything')
            ->getJson('/api/henry/health');
        $response->assertStatus(503);
    });

    it('returns health summary with correct token', function () {
        Person::factory()->create(['contact_type' => 'CLIENT']);
        Person::factory()->create(['contact_type' => 'LEAD']);
        Person::factory()->create(['contact_type' => 'LEAD']);

        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->getJson('/api/henry/health');

        $response->assertOk()
            ->assertJson([
                'status'        => 'ok',
                'people_count'  => 3,
                'clients_count' => 1,
                'leads_count'   => 2,
            ]);
    });

    it('searches people by name, email, or phone', function () {
        $alice = Person::factory()->create([
            'first_name' => 'Alice',
            'last_name'  => 'Smith',
            'email'      => 'alice@example.com',
        ]);
        Person::factory()->create([
            'first_name' => 'Bob',
            'last_name'  => 'Jones',
            'email'      => 'bob@example.com',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->getJson('/api/henry/people/search?q=alice');

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.email', 'alice@example.com');
    });

    it('returns empty results for blank search query', function () {
        Person::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->getJson('/api/henry/people/search?q=');

        $response->assertOk()
            ->assertJson(['results' => []]);
    });

    it('returns 404 for unknown person', function () {
        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->getJson('/api/henry/people/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    });

    it('returns person summary including metrics and recent transactions', function () {
        $person = Person::factory()->create([
            'first_name' => 'Carol',
            'last_name'  => 'Davis',
            'email'      => 'carol@example.com',
        ]);

        PersonMetric::create([
            'id'                       => \Illuminate\Support\Str::uuid()->toString(),
            'person_id'                => $person->id,
            'total_deposits_cents'     => 500000, // $5,000
            'total_withdrawals_cents'  => 100000, // $1,000
            'net_deposits_cents'       => 400000,
            'days_since_last_deposit'  => 5,
            'days_since_last_login'    => 2,
            'has_markets'              => true,
            'has_capital'              => false,
            'has_academy'              => false,
            'refreshed_at'             => now(),
        ]);

        Transaction::factory()->create([
            'person_id'    => $person->id,
            'category'     => 'EXTERNAL_DEPOSIT',
            'amount_cents' => 100000,
            'currency'     => 'USD',
            'status'       => 'DONE',
            'occurred_at'  => now()->subDay(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->getJson("/api/henry/people/{$person->id}");

        $response->assertOk()
            ->assertJsonPath('email', 'carol@example.com')
            ->assertJsonPath('name', 'Carol Davis')
            ->assertJsonPath('metrics.has_markets', true);

        $body = $response->json();
        expect($body['metrics']['total_deposits_usd'])->toEqual(5000);
        expect($body['metrics']['net_deposits_usd'])->toEqual(4000);
        expect($body['recent_transactions'][0]['amount_usd'])->toEqual(1000);
    });

    it('returns book-level metrics', function () {
        Person::factory()->create(['contact_type' => 'CLIENT']);

        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->getJson('/api/henry/metrics/book');

        $response->assertOk()
            ->assertJsonStructure([
                'as_of',
                'people' => ['total', 'leads', 'clients'],
                'deposits_usd' => ['today', 'mtd'],
                'withdrawals_usd' => ['today', 'mtd'],
                'challenge_purchases_usd' => ['today', 'mtd'],
                'dormant_clients' => ['over_14_days', 'over_30_days'],
            ]);
    });

    // ── postEvent ───────────────────────────────────────────────────────────

    it('records an event attached to a person as an Activity row', function () {
        $person = Person::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->postJson('/api/henry/events', [
                'event_type'  => 'henry_observation',
                'person_id'   => $person->id,
                'description' => 'Client mentioned dissatisfaction with platform speed.',
                'metadata'    => ['priority' => 'medium'],
            ]);

        $response->assertCreated()
            ->assertJson(['recorded' => true, 'attached' => true]);

        $activity = \App\Models\Activity::where('person_id', $person->id)->first();
        expect($activity)->not->toBeNull();
        expect($activity->description)->toContain('[Henry/henry_observation]');
        expect($activity->metadata['source'])->toBe('henry');
        expect($activity->metadata['priority'])->toBe('medium');
    });

    it('records an unattached event when person_id is omitted', function () {
        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->postJson('/api/henry/events', [
                'event_type'  => 'sync_concern',
                'description' => 'I noticed the MTR sync logs look odd.',
            ]);

        $response->assertOk()
            ->assertJson(['recorded' => true, 'attached' => false]);
        expect(\App\Models\Activity::count())->toBe(0);
    });

    it('returns 404 when posting an event for an unknown person', function () {
        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->postJson('/api/henry/events', [
                'event_type'  => 'x',
                'person_id'   => '00000000-0000-0000-0000-000000000000',
                'description' => 'y',
            ]);

        $response->assertStatus(404);
    });

    it('rejects events with missing required fields', function () {
        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->postJson('/api/henry/events', ['description' => 'no event_type']);
        $response->assertStatus(422);
    });

    // ── pauseAutonomous ─────────────────────────────────────────────────────

    it('flips the autonomous kill switch on with action=pause', function () {
        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->postJson('/api/henry/actions/pause-autonomous', [
                'action' => 'pause',
                'reason' => 'Henry detected a compliance pattern that needs review.',
            ]);

        $response->assertOk()->assertJson(['state' => 'paused']);

        $guard = app(\App\Services\AI\CostCeilingGuard::class);
        expect($guard->isManuallyPaused())->toBeTrue();
    });

    it('flips the autonomous kill switch off with action=resume', function () {
        // Pre-pause
        app(\App\Services\AI\CostCeilingGuard::class)->pauseAutonomous();

        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->postJson('/api/henry/actions/pause-autonomous', ['action' => 'resume']);

        $response->assertOk()->assertJson(['state' => 'resumed']);

        $guard = app(\App\Services\AI\CostCeilingGuard::class);
        expect($guard->isManuallyPaused())->toBeFalse();
    });

    it('rejects invalid action values', function () {
        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->postJson('/api/henry/actions/pause-autonomous', ['action' => 'destroy_all']);
        $response->assertStatus(422);
    });

    it('rejects pause requests without auth', function () {
        $response = $this->postJson('/api/henry/actions/pause-autonomous', ['action' => 'pause']);
        $response->assertStatus(401);
    });

});
