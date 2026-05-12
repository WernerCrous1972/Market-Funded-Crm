<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Filament\Widgets\Kpi\Concerns\BranchBreakdownChart;

class DepositsByBranchChart extends BranchBreakdownChart
{
    protected static ?string $heading = 'Deposits by Branch';
    protected static ?int    $sort    = 10;

    protected function metric(): string
    {
        return 'deposits';
    }

    protected function color(): string
    {
        return 'rgba(34, 197, 94, 0.7)'; // green
    }
}
