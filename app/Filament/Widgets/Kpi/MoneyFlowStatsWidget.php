<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Services\Kpi\KpiPeriod;
use App\Services\Kpi\KpiQuery;
use App\Services\Kpi\KpiScope;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Top-of-page money-flow tiles for the KPI dashboard.
 * Reads the page-level `period` filter; scopes to current agent unless the
 * user is a manager/admin (in which case it shows company totals).
 */
class MoneyFlowStatsWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $kpi    = app(KpiQuery::class);
        $period = KpiPeriod::fromFilters($this->filters ?? []);
        $scope  = $this->resolveScope();

        $deposits        = $kpi->depositsTotalCents($period, $scope);
        $withdrawals     = $kpi->withdrawalsTotalCents($period, $scope);
        $nett            = $deposits - $withdrawals;
        $challengeSales  = $kpi->challengeSalesCents($period, $scope);

        $scopeLabel = $scope->isCompany() ? 'Company-wide' : 'Your book';

        return [
            Stat::make('Deposits', $this->fmt($deposits))
                ->description($scopeLabel . ' · ' . $period->label())
                ->color('success'),

            Stat::make('Withdrawals', $this->fmt($withdrawals))
                ->description($scopeLabel . ' · ' . $period->label())
                ->color('warning'),

            Stat::make('NETT Deposits', $this->fmt($nett))
                ->description($scopeLabel . ' · ' . $period->label())
                ->color($nett >= 0 ? 'success' : 'danger'),

            Stat::make('Challenge Sales', $this->fmt($challengeSales))
                ->description($scopeLabel . ' · ' . $period->label())
                ->color('info'),
        ];
    }

    private function resolveScope(): KpiScope
    {
        $user = auth()->user();
        if ($user?->is_super_admin || in_array($user?->role, ['ADMIN', 'SALES_MANAGER'], true)) {
            return KpiScope::company();
        }
        return KpiScope::agent($user->id);
    }

    private function fmt(int $cents): string
    {
        $usd  = $cents / 100;
        $sign = $usd < 0 ? '-' : '';
        return $sign . '$' . number_format(abs($usd), 2);
    }
}
