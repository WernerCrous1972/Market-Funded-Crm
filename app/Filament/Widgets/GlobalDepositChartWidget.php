<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Person;
use App\Models\Transaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * 90-day deposit/withdrawal chart for the dashboard.
 *
 * Shows all EXTERNAL_DEPOSIT and EXTERNAL_WITHDRAWAL transactions
 * grouped by week across all people.
 */
class GlobalDepositChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Deposits & Withdrawals — Last 90 Days';

    protected static ?string $pollingInterval = null;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();
        return $user?->is_super_admin || $user?->can_view_branch_financials;
    }

    protected static ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $since     = now()->subDays(90)->startOfDay();
        $user      = auth()->user();
        $managerId = $user?->is_super_admin ? null : $user?->id;

        $transactions = Transaction::whereIn('category', ['EXTERNAL_DEPOSIT', 'EXTERNAL_WITHDRAWAL'])
            ->where('status', 'DONE')
            ->where('occurred_at', '>=', $since)
            ->when($managerId, fn ($q) => $q->whereIn('person_id',
                Person::where('account_manager_user_id', $managerId)->select('id')
            ))
            ->orderBy('occurred_at')
            ->get(['occurred_at', 'category', 'amount_cents']);

        $labels  = [];
        $depData = [];
        $wdData  = [];

        for ($i = 12; $i >= 0; $i--) {
            $start = now()->subWeeks($i)->startOfWeek();
            $key   = $start->format('Y-W');

            $labels[]      = $start->format('d M');
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
