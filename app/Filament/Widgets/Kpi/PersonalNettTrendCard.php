<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

/**
 * Full-width NETT trend line for the Sales tab.
 *
 * Same data path as NettTrendCard but takes full page width — gives the
 * agent (or manager) a single big "how have I/we been doing" curve below
 * the leaderboard. Heading reflects scope so agents see "Your NETT"
 * rather than "NETT Deposits."
 */
class PersonalNettTrendCard extends MoneyMetricTrendWidget
{
    protected static ?int $sort = 100;

    protected int|string|array $columnSpan = 'full';

    protected function metricHeading(): string
    {
        $user = auth()->user();
        $isManager = $user?->is_super_admin
            || in_array($user?->role, ['ADMIN', 'SALES_MANAGER'], true);
        return $isManager ? 'Company NETT Trend' : 'Your NETT Trend';
    }

    protected function metric(): string
    {
        return 'nett';
    }

    protected function sparkColor(): string
    {
        return 'rgb(59, 130, 246)';
    }
}
