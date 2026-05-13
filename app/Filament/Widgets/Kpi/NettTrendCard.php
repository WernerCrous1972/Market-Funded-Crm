<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

class NettTrendCard extends MoneyMetricTrendWidget
{
    protected static ?int $sort = 12;

    protected function metricHeading(): string { return 'NETT Deposits'; }
    protected function metric(): string { return 'nett'; }
    protected function sparkColor(): string { return 'rgb(59, 130, 246)'; }
}
