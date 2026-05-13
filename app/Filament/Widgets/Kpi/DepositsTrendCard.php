<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

class DepositsTrendCard extends MoneyMetricTrendWidget
{
    protected static ?int $sort = 10;

    protected function metricHeading(): string { return 'Deposits'; }
    protected function metric(): string { return 'deposits'; }
    protected function sparkColor(): string { return 'rgb(34, 197, 94)'; }
}
