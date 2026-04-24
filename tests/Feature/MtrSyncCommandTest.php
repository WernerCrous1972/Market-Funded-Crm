<?php

declare(strict_types=1);

use App\Services\MatchTrader\Client;
use Illuminate\Support\Facades\Log;

describe('mtr:sync command', function () {
    it('requires a mode flag', function () {
        $this->artisan('mtr:sync')
            ->assertExitCode(2); // INVALID
    });

    it('dry-run full sync logs without writing to database', function () {
        // Mock the MTR client to return minimal data
        $mockClient = Mockery::mock(Client::class);

        $mockClient->shouldReceive('branches')->andReturn([]);
        $mockClient->shouldReceive('offers')->andReturn([]);
        $mockClient->shouldReceive('allPropChallenges')->andReturn((function () { yield from []; })());
        $mockClient->shouldReceive('allAccounts')->andReturn((function () { yield from []; })());
        $mockClient->shouldReceive('allDeposits')->andReturn((function () { yield from []; })());
        $mockClient->shouldReceive('allWithdrawals')->andReturn((function () { yield from []; })());

        $this->app->instance(Client::class, $mockClient);

        $this->artisan('mtr:sync --full --dry-run')
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        // No records written
        expect(\App\Models\Person::count())->toBe(0);
        expect(\App\Models\Branch::count())->toBe(0);
    });

    it('offers-only flag only runs branch and offer jobs', function () {
        $mockClient = Mockery::mock(Client::class);

        $mockClient->shouldReceive('branches')->once()->andReturn([]);
        $mockClient->shouldReceive('offers')->once()->andReturn([]);
        $mockClient->shouldReceive('allPropChallenges')->once()->andReturn((function () { yield from []; })());

        // These should NOT be called
        $mockClient->shouldNotReceive('allAccounts');
        $mockClient->shouldNotReceive('allDeposits');
        $mockClient->shouldNotReceive('allWithdrawals');

        $this->app->instance(Client::class, $mockClient);

        $this->artisan('mtr:sync --offers-only --dry-run')
            ->assertSuccessful();
    });
});
