<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\GlobalDepositChartWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            StatsOverviewWidget::class,
            GlobalDepositChartWidget::class,
            RecentActivityWidget::class,
        ];
    }
}
