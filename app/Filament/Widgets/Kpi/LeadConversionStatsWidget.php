<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Services\Kpi\KpiPeriod;
use App\Services\Kpi\KpiQuery;
use App\Services\Kpi\KpiScope;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LeadConversionStatsWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $kpi    = app(KpiQuery::class);
        $period = KpiPeriod::fromFilters($this->filters ?? []);
        $scope  = $this->resolveScope();

        $conversions = $kpi->conversionsCount($period, $scope);
        $rate        = $kpi->conversionRate($period, $scope);
        $scopeLabel  = $scope->isCompany() ? 'Company-wide' : 'Your book';

        return [
            Stat::make('Lead → Client Conversions', (string) $conversions)
                ->description($scopeLabel . ' · ' . $period->label())
                ->color('success'),

            Stat::make('Conversion Rate', number_format($rate * 100, 2) . '%')
                ->description($scopeLabel . ' · leads converted ÷ eligible pool')
                ->color($rate >= 0.10 ? 'success' : ($rate >= 0.05 ? 'warning' : 'gray')),
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
}
