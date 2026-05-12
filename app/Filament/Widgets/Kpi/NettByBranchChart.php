<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Filament\Widgets\Kpi\Concerns\BranchBreakdownChart;

class NettByBranchChart extends BranchBreakdownChart
{
    protected static ?string $heading = 'NETT Deposits by Branch';
    protected static ?int    $sort    = 12;

    protected function metric(): string
    {
        return 'nett';
    }

    protected function color(): string
    {
        return 'rgba(59, 130, 246, 0.7)'; // blue
    }
}
