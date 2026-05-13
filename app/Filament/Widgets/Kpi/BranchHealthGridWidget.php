<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Services\Kpi\KpiPeriod;
use App\Services\Kpi\KpiQuery;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Per-branch tile grid. One small card per branch that has any people
 * attached, regardless of outreach_enabled — this is a *read* view of
 * branch health, not the write-side outreach gate.
 *
 * Each card carries 4 figures: New Leads / New Clients / Challenge Sales /
 * NETT Deposits. Period-scoped via the page filter.
 */
class BranchHealthGridWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.kpi.branch-health-grid';

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        $kpi    = app(KpiQuery::class);
        $period = KpiPeriod::fromFilters($this->filters ?? []);
        $rows   = $kpi->branchHealthGrid($period);

        return [
            'period' => $period,
            'rows'   => $rows,
        ];
    }
}
