<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Filament\Widgets\Kpi\Concerns\AgentBreakdownChart;

class DepositsValueByAgentChart extends AgentBreakdownChart
{
    protected static ?string $heading = 'Deposits — value ($) per agent';
    protected static ?int    $sort    = 111;

    protected function metric(): string { return 'deposits'; }
    protected function mode(): string   { return 'value'; }
    protected function color(): string  { return 'rgba(34, 197, 94, 0.85)'; }
}
