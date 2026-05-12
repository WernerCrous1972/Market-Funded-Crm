<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Filament\Widgets\Kpi\Concerns\BranchBreakdownChart;

class WithdrawalsByBranchChart extends BranchBreakdownChart
{
    protected static ?string $heading = 'Withdrawals by Branch';
    protected static ?int    $sort    = 11;

    protected function metric(): string
    {
        return 'withdrawals';
    }

    protected function color(): string
    {
        return 'rgba(245, 158, 11, 0.7)'; // amber
    }
}
