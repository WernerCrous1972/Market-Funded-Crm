<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Filament\Widgets\Kpi\Concerns\BranchBreakdownChart;

class ChallengeSalesByBranchChart extends BranchBreakdownChart
{
    protected static ?string $heading = 'Challenge Sales by Branch';
    protected static ?int    $sort    = 13;

    protected function metric(): string
    {
        return 'challenge_sales';
    }

    protected function color(): string
    {
        return 'rgba(168, 85, 247, 0.7)'; // purple
    }
}
