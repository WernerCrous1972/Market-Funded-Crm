<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Services\Kpi\KpiPeriod;
use App\Services\Kpi\KpiQuery;
use App\Services\Kpi\KpiScope;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * A money-metric card with count + value in the heading + a line chart
 * of the metric over the period.
 *
 * Extends Filament's ChartWidget so chart.js loading is handled for free.
 * The count + value pair lives in the widget heading and description.
 *
 * Subclasses set:
 *   - heading()         — display label ("Deposits", "Withdrawals", …)
 *   - metric()          — 'deposits' | 'withdrawals' | 'nett' | 'challenge_sales'
 *   - sparkColor()      — rgb() for the line + transparent fill
 *
 * Scoped by role: managers/admins see company; agents see their own book.
 */
abstract class MoneyMetricTrendWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = ['default' => 'full', 'md' => 1];

    protected static ?string $maxHeight = '180px';

    abstract protected function metricHeading(): string;
    abstract protected function metric(): string;
    abstract protected function sparkColor(): string;

    public function getHeading(): ?string
    {
        return $this->metricHeading();
    }

    public function getDescription(): ?string
    {
        $kpi    = app(KpiQuery::class);
        $period = KpiPeriod::fromFilters($this->filters ?? []);
        $scope  = $this->resolveScope();
        $metric = $this->metric();

        [$count, $valueCents] = $this->countAndValue($kpi, $period, $scope, $metric);

        $usd = $valueCents / 100;
        $sign = $usd < 0 ? '-' : '';
        $val  = $sign . '$' . number_format(abs($usd), 2);

        return number_format($count) . ' · ' . $val . ' · ' . $period->label();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $kpi    = app(KpiQuery::class);
        $period = KpiPeriod::fromFilters($this->filters ?? []);
        $scope  = $this->resolveScope();
        $series = $kpi->dailyTrend($this->metric(), $period, $scope);

        return [
            'datasets' => [
                [
                    'label'           => $this->metricHeading(),
                    'data'            => $series->pluck('value_cents')->map(fn ($c) => round($c / 100, 2))->all(),
                    'borderColor'     => $this->sparkColor(),
                    'backgroundColor' => str_replace('rgb(', 'rgba(', rtrim($this->sparkColor(), ')')) . ', 0.15)',
                    'borderWidth'     => 2,
                    'fill'            => true,
                    'pointRadius'     => 0,
                    'tension'         => 0.3,
                ],
            ],
            'labels' => $series->pluck('label')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'x' => ['ticks' => ['maxTicksLimit' => 6, 'autoSkip' => true]],
                'y' => ['ticks' => ['callback' => 'function(v){return "$"+v.toLocaleString();}']],
            ],
        ];
    }

    private function countAndValue(KpiQuery $kpi, KpiPeriod $period, KpiScope $scope, string $metric): array
    {
        return match ($metric) {
            'deposits'        => [$kpi->depositsCount($period, $scope),       $kpi->depositsTotalCents($period, $scope)],
            'withdrawals'     => [$kpi->withdrawalsCount($period, $scope),    $kpi->withdrawalsTotalCents($period, $scope)],
            'nett'            => [
                $kpi->depositsCount($period, $scope) - $kpi->withdrawalsCount($period, $scope),
                $kpi->nettDepositsCents($period, $scope),
            ],
            'challenge_sales' => [$kpi->challengeSalesCount($period, $scope), $kpi->challengeSalesCents($period, $scope)],
        };
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
