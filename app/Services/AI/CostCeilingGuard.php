<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiUsageLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Decides whether AI work is allowed right now.
 *
 *   check() returns one of:
 *     - State::Proceed         — under both caps; everything goes
 *     - State::PauseAutonomous — over soft cap; reviewed work still goes,
 *                                autonomous triggers do not fire
 *     - State::PauseAll        — over hard cap OR manual kill switch active;
 *                                NO AI calls (compliance, classify, drafts)
 *
 * Source of truth for spend: ai_usage_log, summed for the current month
 * in Africa/Johannesburg time. Result cached for 60s so the AI Ops
 * dashboard + every send don't hammer the DB.
 *
 * Kill switch lives at cache key `ai:autonomous_paused` (string "true"/"false").
 * Falls through to config('ai.autonomous_paused') env var on cache miss
 * so app boot honours the env even before anything has written the cache.
 */
class CostCeilingGuard
{
    public const CACHE_KEY_KILL_SWITCH      = 'ai:autonomous_paused';
    public const CACHE_KEY_MONTHLY_SPEND    = 'ai:cost:current_month_cents';
    public const SPEND_CACHE_TTL_SECONDS    = 60;

    public function check(): GuardState
    {
        if ($this->isManuallyPaused()) {
            return GuardState::PauseAll;
        }

        $spentCents = $this->currentMonthSpendCents();
        $softCents  = (int) (config('ai.cost_caps.soft_usd', 300) * 100);
        $hardCents  = (int) (config('ai.cost_caps.hard_usd', 500) * 100);

        if ($spentCents >= $hardCents) {
            return GuardState::PauseAll;
        }

        if ($spentCents >= $softCents) {
            return GuardState::PauseAutonomous;
        }

        return GuardState::Proceed;
    }

    /**
     * Convenience for the common case "is this autonomous send allowed?".
     */
    public function allowsAutonomous(): bool
    {
        return $this->check() === GuardState::Proceed;
    }

    /**
     * Convenience for "is this AI call allowed at all?" — used by reviewed
     * drafts and by the compliance/inbound-classify pipelines.
     */
    public function allowsAnyCall(): bool
    {
        $state = $this->check();
        return $state === GuardState::Proceed || $state === GuardState::PauseAutonomous;
    }

    public function currentMonthSpendCents(): int
    {
        $cached = Cache::get(self::CACHE_KEY_MONTHLY_SPEND);
        if (is_int($cached)) {
            return $cached;
        }

        $monthStart = CarbonImmutable::now('Africa/Johannesburg')->startOfMonth()->toDateString();

        $sum = (int) AiUsageLog::where('date', '>=', $monthStart)->sum('cost_cents');

        Cache::put(self::CACHE_KEY_MONTHLY_SPEND, $sum, self::SPEND_CACHE_TTL_SECONDS);

        return $sum;
    }

    public function pauseAutonomous(): void
    {
        Cache::forever(self::CACHE_KEY_KILL_SWITCH, true);
    }

    public function resumeAutonomous(): void
    {
        Cache::forget(self::CACHE_KEY_KILL_SWITCH);
    }

    public function isManuallyPaused(): bool
    {
        $cached = Cache::get(self::CACHE_KEY_KILL_SWITCH);
        if ($cached !== null) {
            return (bool) $cached;
        }
        return (bool) config('ai.autonomous_paused', false);
    }

    /**
     * Force the spend cache to refresh on next read. Called by ModelRouter
     * could be wired here if needed; for now we just trust the 60s TTL.
     */
    public function invalidateSpendCache(): void
    {
        Cache::forget(self::CACHE_KEY_MONTHLY_SPEND);
    }
}
