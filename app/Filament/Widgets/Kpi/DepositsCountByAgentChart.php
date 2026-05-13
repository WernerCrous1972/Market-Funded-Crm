<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Filament\Widgets\Kpi\Concerns\AgentBreakdownChart;

class DepositsCountByAgentChart extends AgentBreakdownChart
{
    protected static ?string $heading = 'Deposits — count per agent';
    protected static ?int    $sort    = 110;

    protected function metric(): string { return 'deposits'; }
    protected function mode(): string   { return 'count'; }
    protected function color(): string  { return 'rgba(34, 197, 94, 0.7)'; }
}
