<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Filament\Widgets\Kpi\Concerns\AgentBreakdownChart;

class ChallengeSalesValueByAgentChart extends AgentBreakdownChart
{
    protected static ?string $heading = 'Challenge Sales — value ($) per agent';
    protected static ?int    $sort    = 113;

    protected function metric(): string { return 'challenge_sales'; }
    protected function mode(): string   { return 'value'; }
    protected function color(): string  { return 'rgba(168, 85, 247, 0.85)'; }
}
