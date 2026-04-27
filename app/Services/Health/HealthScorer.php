<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Models\PersonMetric;

/**
 * Rule-based health scorer for MFU clients.
 *
 * Scores are 0–100. Only CLIENT records are scored (LEADs have no trading activity).
 *
 * ── Active factors (data available now) ──────────────────────────────────────
 *
 * Factor 1 — Login recency          max ±15 pts
 *   +15  logged in within 7 days
 *   +5   logged in 8–14 days ago
 *   -15  not logged in for 15+ days (or never)
 *
 * Factor 2 — Deposit recency        max ±20 pts
 *   +20  deposited within 30 days
 *   +10  deposited 31–60 days ago
 *   -20  no deposit in 60+ days (or never deposited)
 *
 * Factor 3 — Deposit/withdrawal ratio  max ±15 pts
 *   Ratio = total_deposits / max(total_withdrawals, 1)
 *   >= 3.0  → +15 (healthy — withdrawing much less than depositing)
 *   >= 1.5  → +8
 *   >= 1.0  → 0  (breaking even)
 *   < 1.0   → -15 (withdrawing more than depositing)
 *   No deposits at all → -15
 *
 * Factor 4 — Net deposit trend      max ±10 pts
 *   Compares deposits_mtd vs average monthly deposits (all-time / months active)
 *   MTD >= 80% of monthly average  → +10
 *   MTD >= 40%                     → +5
 *   MTD < 40% (or no history)      → -10
 *
 * ── Deferred factors (require equity snapshot / open position data) ──────────
 * Factor 5 — Equity change over 30 days  ±20 pts  TODO Phase 4
 * Factor 6 — Open positions count        +10 pts  TODO Phase 4
 *
 * Base score starts at 50 (neutral). Factors adjust from there, clamped 0–100.
 */
class HealthScorer
{
    public const BASE_SCORE = 50;

    public const GRADE_THRESHOLDS = [
        'A' => 80,
        'B' => 65,
        'C' => 50,
        'D' => 35,
        // below 35 = F
    ];

    /**
     * Score a single PersonMetric record.
     *
     * Returns an array with:
     *   score       int   0–100
     *   grade       string A/B/C/D/F
     *   breakdown   array  per-factor detail for display
     */
    public function score(PersonMetric $metrics): array
    {
        $breakdown = [];
        $adjustment = 0;

        // ── Factor 1: Login recency ───────────────────────────────────────────
        $loginDays = $metrics->days_since_last_login;
        $loginPoints = match (true) {
            $loginDays === null      => -15,
            $loginDays <= 7          => +15,
            $loginDays <= 14         => +5,
            default                  => -15,
        };
        $adjustment += $loginPoints;
        $breakdown['login_recency'] = [
            'label'       => 'Login Recency',
            'points'      => $loginPoints,
            'detail'      => $loginDays === null
                ? 'Never logged in'
                : "{$loginDays} days since last login",
        ];

        // ── Factor 2: Deposit recency ─────────────────────────────────────────
        $depositDays = $metrics->days_since_last_deposit;
        $depositPoints = match (true) {
            $depositDays === null    => -20,
            $depositDays <= 30       => +20,
            $depositDays <= 60       => +10,
            default                  => -20,
        };
        $adjustment += $depositPoints;
        $breakdown['deposit_recency'] = [
            'label'       => 'Deposit Recency',
            'points'      => $depositPoints,
            'detail'      => $depositDays === null
                ? 'Never deposited'
                : "{$depositDays} days since last deposit",
        ];

        // ── Factor 3: Deposit / withdrawal ratio ──────────────────────────────
        $totalDep = $metrics->total_deposits_cents;
        $totalWd  = $metrics->total_withdrawals_cents;

        if ($totalDep === 0) {
            $ratioPoints = -15;
            $ratioDetail = 'No deposits recorded';
        } else {
            $ratio = $totalDep / max($totalWd, 1);
            $ratioPoints = match (true) {
                $ratio >= 3.0 => +15,
                $ratio >= 1.5 => +8,
                $ratio >= 1.0 => 0,
                default       => -15,
            };
            $ratioDetail = sprintf(
                '$%s deposited / $%s withdrawn (ratio %.1f)',
                number_format($totalDep / 100, 0),
                number_format($totalWd / 100, 0),
                $ratio
            );
        }
        $adjustment += $ratioPoints;
        $breakdown['deposit_withdrawal_ratio'] = [
            'label'  => 'Deposit/Withdrawal Ratio',
            'points' => $ratioPoints,
            'detail' => $ratioDetail,
        ];

        // ── Factor 4: Net deposit trend (MTD vs monthly average) ──────────────
        $mtdDep      = $metrics->deposits_mtd_cents;
        $firstDeposit = $metrics->first_deposit_at;

        if ($totalDep === 0 || $firstDeposit === null) {
            $trendPoints = -10;
            $trendDetail = 'No deposit history';
        } else {
            // Months active = months between first deposit and now (min 1)
            $monthsActive = max(1, (int) $firstDeposit->diffInMonths(now()));
            $monthlyAvg   = $totalDep / $monthsActive;

            if ($monthlyAvg === 0.0) {
                $trendPoints = -10;
                $trendDetail = 'No monthly average';
            } else {
                $mtdRatio    = $mtdDep / $monthlyAvg;
                $trendPoints = match (true) {
                    $mtdRatio >= 0.8 => +10,
                    $mtdRatio >= 0.4 => +5,
                    default          => -10,
                };
                $trendDetail = sprintf(
                    '$%s MTD vs $%s/mo avg (%d%%)',
                    number_format($mtdDep / 100, 0),
                    number_format($monthlyAvg / 100, 0),
                    (int) ($mtdRatio * 100)
                );
            }
        }
        $adjustment += $trendPoints;
        $breakdown['deposit_trend'] = [
            'label'  => 'Deposit Trend (MTD)',
            'points' => $trendPoints,
            'detail' => $trendDetail,
        ];

        // ── TODO Phase 4: Equity change ±20, Open positions +10 ──────────────
        $breakdown['equity_change'] = [
            'label'  => 'Equity Change (30d)',
            'points' => 0,
            'detail' => 'Pending equity snapshot data (Phase 4)',
            'pending' => true,
        ];
        $breakdown['open_positions'] = [
            'label'  => 'Open Positions',
            'points' => 0,
            'detail' => 'Pending position data (Phase 4)',
            'pending' => true,
        ];

        // ── Final score ───────────────────────────────────────────────────────
        $score = max(0, min(100, self::BASE_SCORE + $adjustment));
        $grade = $this->grade($score);

        return [
            'score'     => $score,
            'grade'     => $grade,
            'breakdown' => $breakdown,
        ];
    }

    public function grade(int $score): string
    {
        foreach (self::GRADE_THRESHOLDS as $grade => $threshold) {
            if ($score >= $threshold) {
                return $grade;
            }
        }
        return 'F';
    }

    /**
     * Human-readable label for a grade.
     */
    public static function gradeLabel(string $grade): string
    {
        return match ($grade) {
            'A' => 'Healthy',
            'B' => 'Good',
            'C' => 'Neutral',
            'D' => 'At Risk',
            'F' => 'Critical',
            default => 'Unknown',
        };
    }

    /**
     * Filament badge color for a grade.
     */
    public static function gradeColor(string $grade): string
    {
        return match ($grade) {
            'A' => 'success',
            'B' => 'info',
            'C' => 'gray',
            'D' => 'warning',
            'F' => 'danger',
            default => 'gray',
        };
    }
}
