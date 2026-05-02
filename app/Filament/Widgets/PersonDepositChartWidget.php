<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Person;
use App\Models\Transaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * 90-day deposit/withdrawal chart for a single person.
 *
 * Rendered as a line chart grouped by week.
 * Used on the Person view page via getHeaderWidgets().
 */
class PersonDepositChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Deposits & Withdrawals — Last 90 Days';

    public ?string $personId = null;

    protected static ?string $pollingInterval = null; // no auto-refresh

    public static function canView(): bool
    {
        $user = auth()->user();
        return $user?->is_super_admin || $user?->can_view_client_financials;
    }

    protected int | string | array $columnSpan = 'full';

    protected static ?string $maxHeight = '280px';

    protected function getData(): array
    {
        if (! $this->personId) {
            return ['datasets' => [], 'labels' => []];
        }

        $since = now()->subDays(90)->startOfDay();

        // Get all DONE external transactions in the last 90 days, grouped by ISO week
        $transactions = Transaction::where('person_id', $this->personId)
            ->whereIn('category', ['EXTERNAL_DEPOSIT', 'EXTERNAL_WITHDRAWAL'])
            ->where('status', 'DONE')
            ->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')
            ->get(['occurred_at', 'category', 'amount_cents']);

        // Build 13 weekly buckets (90 days / 7 ≈ 13 weeks)
        $weeks   = [];
        $labels  = [];
        $depData = [];
        $wdData  = [];

        for ($i = 12; $i >= 0; $i--) {
            $start  = now()->subWeeks($i)->startOfWeek();
            $end    = now()->subWeeks($i)->endOfWeek();
            $label  = $start->format('d M');
            $key    = $start->format('Y-W');

            $weeks[$key]  = ['start' => $start, 'end' => $end];
            $labels[]     = $label;
            $depData[$key] = 0;
            $wdData[$key]  = 0;
        }

        foreach ($transactions as $tx) {
            $key = Carbon::parse($tx->occurred_at)->format('Y-W');
            if (! isset($depData[$key])) continue;

            if ($tx->category === 'EXTERNAL_DEPOSIT') {
                $depData[$key] += $tx->amount_cents / 100;
            } else {
                $wdData[$key] += $tx->amount_cents / 100;
            }
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Deposits ($)',
                    'data'            => array_values($depData),
                    'borderColor'     => '#22c55e',
                    'backgroundColor' => 'rgba(34,197,94,0.15)',
                    'fill'            => true,
                    'tension'         => 0.4,
                    'pointRadius'     => 3,
                ],
                [
                    'label'           => 'Withdrawals ($)',
                    'data'            => array_values($wdData),
                    'borderColor'     => '#ef4444',
                    'backgroundColor' => 'rgba(239,68,68,0.1)',
                    'fill'            => true,
                    'tension'         => 0.4,
                    'pointRadius'     => 3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks'       => [
                        'callback' => "function(v){return '$'+v.toLocaleString();}",
                    ],
                ],
            ],
            'plugins' => [
                'legend' => ['position' => 'top'],
                'tooltip' => [
                    'callbacks' => [
                        'label' => "function(c){return c.dataset.label+': $'+c.parsed.y.toLocaleString(undefined,{minimumFractionDigits:2});}",
                    ],
                ],
            ],
        ];
    }
}
