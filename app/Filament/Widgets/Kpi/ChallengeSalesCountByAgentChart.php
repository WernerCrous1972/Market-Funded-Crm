<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Filament\Widgets\Kpi\Concerns\AgentBreakdownChart;

class ChallengeSalesCountByAgentChart extends AgentBreakdownChart
{
    protected static ?string $heading = 'Challenge Sales — count per agent';
    protected static ?int    $sort    = 112;

    protected function metric(): string { return 'challenge_sales'; }
    protected function mode(): string   { return 'count'; }
    protected function color(): string  { return 'rgba(168, 85, 247, 0.7)'; }
}
