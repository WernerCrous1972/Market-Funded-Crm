<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

class WithdrawalsTrendCard extends MoneyMetricTrendWidget
{
    protected static ?int $sort = 11;

    protected function metricHeading(): string { return 'Withdrawals'; }
    protected function metric(): string { return 'withdrawals'; }
    protected function sparkColor(): string { return 'rgb(245, 158, 11)'; }
}
