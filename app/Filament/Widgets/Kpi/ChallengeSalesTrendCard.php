<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

class ChallengeSalesTrendCard extends MoneyMetricTrendWidget
{
    protected static ?int $sort = 13;

    protected function metricHeading(): string { return 'Challenge Sales'; }
    protected function metric(): string { return 'challenge_sales'; }
    protected function sparkColor(): string { return 'rgb(168, 85, 247)'; }
}
