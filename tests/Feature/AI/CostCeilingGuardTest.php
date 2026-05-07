<?php

declare(strict_types=1);

use App\Models\AiUsageLog;
use App\Services\AI\CostCeilingGuard;
use App\Services\AI\GuardState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

describe('CostCeilingGuard', function () {

    beforeEach(function () {
        config()->set('ai.cost_caps.soft_usd', 300);
        config()->set('ai.cost_caps.hard_usd', 500);
        config()->set('ai.autonomous_paused', false);
        Cache::flush();
    });

    $insertUsage = function (int $costCents, ?string $date = null, ?string $task = null): void {
        AiUsageLog::create([
            'id'            => Str::uuid()->toString(),
            'date'          => $date ?? now()->toDateString(),
            'task_type'     => $task ?? 'compliance_check_' . Str::random(6),
            'model'         => 'claude-haiku-4-5-20251001',
            'call_count'    => 1,
            'tokens_input'  => 100,
            'tokens_output' => 50,
            'cost_cents'    => $costCents,
        ]);
    };

    it('proceeds when spend is below the soft cap', function () use ($insertUsage) {
        $insertUsage(50_00);  // $50 spend, soft cap $300

        $guard = new CostCeilingGuard();

        expect($guard->check())->toBe(GuardState::Proceed);
        expect($guard->allowsAutonomous())->toBeTrue();
        expect($guard->allowsAnyCall())->toBeTrue();
    });

    it('pauses autonomous when spend crosses the soft cap', function () use ($insertUsage) {
        $insertUsage(310_00); // $310, just over soft

        $guard = new CostCeilingGuard();

        expect($guard->check())->toBe(GuardState::PauseAutonomous);
        expect($guard->allowsAutonomous())->toBeFalse();
        expect($guard->allowsAnyCall())->toBeTrue();  // reviewed still allowed
    });

    it('pauses everything when spend crosses the hard cap', function () use ($insertUsage) {
        $insertUsage(550_00); // $550, over hard

        $guard = new CostCeilingGuard();

        expect($guard->check())->toBe(GuardState::PauseAll);
        expect($guard->allowsAutonomous())->toBeFalse();
        expect($guard->allowsAnyCall())->toBeFalse();
    });

    it('treats the soft cap as inclusive (>= soft pauses)', function () use ($insertUsage) {
        $insertUsage(300_00); // exactly $300

        $guard = new CostCeilingGuard();

        expect($guard->check())->toBe(GuardState::PauseAutonomous);
    });

    it('treats the hard cap as inclusive (>= hard pauses all)', function () use ($insertUsage) {
        $insertUsage(500_00); // exactly $500

        $guard = new CostCeilingGuard();

        expect($guard->check())->toBe(GuardState::PauseAll);
    });

    it('honours the manual kill switch (cache wins over usage)', function () use ($insertUsage) {
        $insertUsage(10_00); // well under soft

        $guard = new CostCeilingGuard();
        $guard->pauseAutonomous();

        expect($guard->check())->toBe(GuardState::PauseAll);
        expect($guard->isManuallyPaused())->toBeTrue();

        $guard->resumeAutonomous();
        expect($guard->isManuallyPaused())->toBeFalse();
        expect($guard->check())->toBe(GuardState::Proceed);
    });

    it('honours the env-level kill switch when no cache value is set', function () {
        config()->set('ai.autonomous_paused', true);

        $guard = new CostCeilingGuard();

        expect($guard->isManuallyPaused())->toBeTrue();
        expect($guard->check())->toBe(GuardState::PauseAll);
    });

    it('only sums spend for the current month', function () use ($insertUsage) {
        // Last month — large amount, must NOT count
        $insertUsage(1000_00, now()->subMonth()->endOfMonth()->toDateString());
        // This month — small amount
        $insertUsage(20_00, now()->startOfMonth()->toDateString());

        $guard = new CostCeilingGuard();

        expect($guard->currentMonthSpendCents())->toBe(20_00);
    });

    it('sums multiple rows within the current month', function () use ($insertUsage) {
        $insertUsage(50_00, now()->startOfMonth()->toDateString());
        $insertUsage(30_00, now()->toDateString());

        $guard = new CostCeilingGuard();

        expect($guard->currentMonthSpendCents())->toBe(80_00);
    });

    it('fires a Telegram once per month when soft cap is first crossed', function () use ($insertUsage) {
        $insertUsage(310_00); // over soft

        $captured = [];
        $telegram = new class($captured) extends \App\Services\Notifications\TelegramNotifier {
            public function __construct(public array &$captured) {}
            public function notify(string $message, string $severity = 'info'): bool {
                $this->captured[] = [$severity, $message];
                return true;
            }
            public function isReachable(): bool { return true; }
        };

        $guard = new CostCeilingGuard($telegram);
        $guard->check();
        $guard->check();
        $guard->check();

        // Only one notification despite three checks
        expect(count($captured))->toBe(1);
        expect($captured[0][0])->toBe('warning');
        expect($captured[0][1])->toContain('SOFT cap crossed');
    });

    it('fires a Telegram once when hard cap is first crossed (severity=critical)', function () use ($insertUsage) {
        $insertUsage(550_00); // over hard

        $captured = [];
        $telegram = new class($captured) extends \App\Services\Notifications\TelegramNotifier {
            public function __construct(public array &$captured) {}
            public function notify(string $message, string $severity = 'info'): bool {
                $this->captured[] = [$severity, $message];
                return true;
            }
            public function isReachable(): bool { return true; }
        };

        $guard = new CostCeilingGuard($telegram);
        $guard->check();
        $guard->check();

        expect(count($captured))->toBe(1);
        expect($captured[0][0])->toBe('critical');
        expect($captured[0][1])->toContain('HARD cap crossed');
    });

    it('caches the spend total for cheap repeated reads', function () use ($insertUsage) {
        $insertUsage(10_00);

        $guard = new CostCeilingGuard();

        expect($guard->currentMonthSpendCents())->toBe(10_00);

        // Add more spend — cache should still report the original
        $insertUsage(50_00);
        expect($guard->currentMonthSpendCents())->toBe(10_00);

        // Invalidate, then we see the new total
        $guard->invalidateSpendCache();
        expect($guard->currentMonthSpendCents())->toBe(60_00);
    });

});
