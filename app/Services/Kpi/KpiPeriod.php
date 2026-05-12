<?php

declare(strict_types=1);

namespace App\Services\Kpi;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Value object representing a KPI reporting window.
 *
 * Keys (the values that go in the page selector):
 *   - 'mtd'       — first of this month → now
 *   - 'last_30d'  — 30 days ago → now
 *   - 'last_90d'  — 90 days ago → now
 *   - 'ytd'       — Jan 1 of this year → now
 *   - 'all_time'  — null start (no lower bound) → now
 *
 * The default is `mtd` per Werner's spec (2026-05-12).
 *
 * `start()` returns null for the all-time window so callers can omit
 * the lower bound. `end()` is always now.
 */
final class KpiPeriod
{
    public const DEFAULT = 'mtd';

    private const OPTIONS = [
        'mtd'      => 'Month to date',
        'last_30d' => 'Last 30 days',
        'last_90d' => 'Last 90 days',
        'ytd'      => 'Year to date',
        'all_time' => 'All time',
        'custom'   => 'Custom range',
    ];

    private ?CarbonImmutable $customStart = null;
    private ?CarbonImmutable $customEnd   = null;

    public function __construct(private readonly string $key)
    {
        if (! isset(self::OPTIONS[$this->key])) {
            throw new InvalidArgumentException("Unknown KPI period: {$this->key}");
        }
    }

    /**
     * Build a custom-range period from two date strings (or Carbon dates).
     * Start defaults to beginning-of-day; end defaults to end-of-day so the
     * range is inclusive on both ends.
     */
    public static function custom(string|CarbonImmutable $start, string|CarbonImmutable $end): self
    {
        $p = new self('custom');
        $p->customStart = $start instanceof CarbonImmutable
            ? $start->startOfDay()
            : CarbonImmutable::parse($start)->startOfDay();
        $p->customEnd = $end instanceof CarbonImmutable
            ? $end->endOfDay()
            : CarbonImmutable::parse($end)->endOfDay();
        if ($p->customEnd->lt($p->customStart)) {
            throw new InvalidArgumentException('Custom period end must be on or after start.');
        }
        return $p;
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    /**
     * Build a KpiPeriod from a Filament page-filter array:
     *   ['period' => 'custom', 'custom_start' => '2026-04-01', 'custom_end' => '2026-04-30']
     *
     * Falls back to the default period when:
     *   - `period` key is absent
     *   - `period` = custom but either date is missing
     *   - `period` = custom but dates parse-fail
     *
     * Defensive on purpose: widgets re-render on every filter keystroke, and
     * a half-typed date should NOT crash the dashboard.
     */
    public static function fromFilters(array $filters): self
    {
        $key = $filters['period'] ?? self::DEFAULT;

        if ($key === 'custom') {
            $start = $filters['custom_start'] ?? null;
            $end   = $filters['custom_end']   ?? null;
            if (! $start || ! $end) {
                return self::default();
            }
            try {
                return self::custom((string) $start, (string) $end);
            } catch (\Throwable) {
                return self::default();
            }
        }

        return new self($key);
    }

    /** @return array<string, string> key => label */
    public static function selectOptions(): array
    {
        return self::OPTIONS;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return self::OPTIONS[$this->key];
    }

    /**
     * Lower bound (inclusive). Null for all-time.
     */
    public function start(): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();
        return match ($this->key) {
            'mtd'      => $now->startOfMonth(),
            'last_30d' => $now->subDays(30)->startOfDay(),
            'last_90d' => $now->subDays(90)->startOfDay(),
            'ytd'      => $now->startOfYear(),
            'all_time' => null,
            'custom'   => $this->customStart
                ?? throw new InvalidArgumentException('Custom period missing start date'),
        };
    }

    public function end(): CarbonImmutable
    {
        if ($this->key === 'custom') {
            return $this->customEnd
                ?? throw new InvalidArgumentException('Custom period missing end date');
        }
        return CarbonImmutable::now();
    }

    /**
     * Stable cache key fragment. Used by KpiQuery for memoisation.
     * Bucket by hour for "all time" (the only window where caching pays
     * off — the others are cheap and tail-sensitive).
     */
    public function cacheKey(): string
    {
        if ($this->key === 'all_time') {
            return 'all_time:' . CarbonImmutable::now()->format('Y-m-d-H');
        }
        if ($this->key === 'custom' && $this->customStart && $this->customEnd) {
            return 'custom:' . $this->customStart->toDateString() . '_' . $this->customEnd->toDateString();
        }
        return $this->key . ':' . CarbonImmutable::now()->format('Y-m-d');
    }

    public function shouldCache(): bool
    {
        return $this->key === 'all_time';
    }
}
