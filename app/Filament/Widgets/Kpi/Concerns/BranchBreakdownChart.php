<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi\Concerns;

use App\Services\Kpi\KpiPeriod;
use App\Services\Kpi\KpiQuery;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Abstract base for per-branch horizontal-bar chart widgets. Concrete
 * subclasses just override:
 *   - protected static string $heading (Filament prop)
 *   - protected function metric(): string  ('deposits' | 'withdrawals' | 'nett' | 'challenge_sales')
 *   - protected function color(): string   (chart.js color name)
 */
abstract class BranchBreakdownChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    /** Subclasses set this with the metric to display. */
    abstract protected function metric(): string;

    /** rgba/hex for the bar colour. */
    abstract protected function color(): string;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $period = KpiPeriod::fromFilters($this->filters ?? []);
        $rows   = app(KpiQuery::class)->perBranchBreakdown($this->metric(), $period);

        return [
            'datasets' => [
                [
                    'label'           => $this->metricLabel(),
                    'data'            => $rows->pluck('value_cents')->map(fn ($c) => round($c / 100, 2))->all(),
                    'backgroundColor' => $this->color(),
                ],
            ],
            'labels' => $rows->pluck('branch_name')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',   // horizontal bars
            'plugins'   => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => [
                    'ticks' => [
                        'callback' => 'function(value){return "$"+value.toLocaleString();}',
                    ],
                ],
            ],
        ];
    }

    private function metricLabel(): string
    {
        return match ($this->metric()) {
            'deposits'        => 'Deposits ($)',
            'withdrawals'     => 'Withdrawals ($)',
            'nett'            => 'NETT Deposits ($)',
            'challenge_sales' => 'Challenge Sales ($)',
            default           => 'Value',
        };
    }
}
