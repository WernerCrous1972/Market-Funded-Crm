<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi\Concerns;

use App\Services\Kpi\KpiPeriod;
use App\Services\Kpi\KpiQuery;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Abstract base for per-account-manager horizontal-bar chart widgets.
 *
 * Subclasses override:
 *   - protected static ?string $heading
 *   - protected function metric(): string  ('deposits' | 'challenge_sales')
 *   - protected function mode(): string    ('value' | 'count')
 *   - protected function color(): string   (rgba/hex)
 *
 * Scope rule (mirrors the leaderboard):
 *   - admin / SALES_MANAGER → all agents visible, full names
 *   - SALES_AGENT → only their own bar + a faded "Company avg" bar.
 *     Other agents' names never leak.
 */
abstract class AgentBreakdownChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 1;

    abstract protected function metric(): string;
    abstract protected function mode(): string;
    abstract protected function color(): string;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $period = KpiPeriod::fromFilters($this->filters ?? []);
        $kpi    = app(KpiQuery::class);
        $allRows = $kpi->perAgentBreakdown($this->metric(), $this->mode(), $period);

        $user = auth()->user();
        $isManager = $user?->is_super_admin
            || in_array($user?->role, ['ADMIN', 'SALES_MANAGER'], true);

        if ($isManager) {
            $labels = $allRows->pluck('user_name')->all();
            $values = $allRows->pluck('value')->map(fn ($v) => $this->shape($v))->all();
            $colors = array_fill(0, count($values), $this->color());
        } else {
            // Agent view: own bar + faded company-average bar.
            $userId = $user?->id;
            $own = $allRows->firstWhere('user_id', $userId);
            $ownValue = $own?->value ?? 0;
            $avgValue = $allRows->count() > 0
                ? (int) round($allRows->avg('value'))
                : 0;

            $labels = ['You', 'Company avg'];
            $values = [$this->shape($ownValue), $this->shape($avgValue)];
            $colors = [$this->color(), $this->fadedColor()];
        }

        return [
            'datasets' => [
                [
                    'label'           => $this->datasetLabel(),
                    'data'            => $values,
                    'backgroundColor' => $colors,
                    'borderColor'     => $colors,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        $tickCallback = $this->mode() === 'value'
            ? 'function(value){return "$"+value.toLocaleString();}'
            : 'function(value){return value.toLocaleString();}';

        return [
            'indexAxis' => 'y',
            'plugins'   => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'ticks' => ['callback' => $tickCallback],
                ],
            ],
        ];
    }

    /**
     * For value mode return USD dollars (cents/100), for count mode return raw.
     */
    private function shape(int $raw): float|int
    {
        return $this->mode() === 'value' ? round($raw / 100, 2) : $raw;
    }

    private function fadedColor(): string
    {
        // Lighten by lowering alpha; assumes a "rgba(r, g, b, a)" string.
        if (preg_match('/^rgba\(([^)]+)\)$/', $this->color(), $m)) {
            $parts = array_map('trim', explode(',', $m[1]));
            if (count($parts) === 4) {
                $parts[3] = '0.25';
                return 'rgba(' . implode(', ', $parts) . ')';
            }
        }
        return 'rgba(156, 163, 175, 0.4)'; // fallback grey
    }

    private function datasetLabel(): string
    {
        $metric = match ($this->metric()) {
            'deposits'        => 'Deposits',
            'challenge_sales' => 'Challenge Sales',
            default           => 'Value',
        };
        return $metric . ' (' . $this->mode() . ')';
    }
}
