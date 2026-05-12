<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Kpi\KpiPeriod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;

/**
 * KPI dashboard — two tabs, single period selector at the top.
 *
 *   - Management Overview tab: money-flow stat tiles + per-branch breakdowns,
 *     framed for the "what does management need to know in 30 seconds" lens.
 *   - Sales Performance tab: Hunter/Farmer leaderboard. Agent role sees only
 *     their own row + anonymised company averages; managers + admins see all.
 *
 * Role gating:
 *   - ADMIN / SALES_MANAGER : full company view, all rows visible
 *   - SALES_AGENT           : own metrics only + company average for comparison
 *
 * The page itself just holds the period filter and renders widgets — all
 * arithmetic lives in App\Services\Kpi\KpiQuery.
 */
class KpiDashboardPage extends Page
{
    use HasFiltersForm;

    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar-square';
    protected static ?string $navigationLabel = 'KPIs';
    protected static ?string $navigationGroup = 'Reports';
    protected static ?int    $navigationSort  = 1;
    protected static ?string $title           = 'KPI Dashboard';
    protected static string  $view            = 'filament.pages.kpi-dashboard';

    public ?string $activeTab = 'overview';

    public static function getSlug(): string
    {
        return 'kpis';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user) return false;

        return $user->is_super_admin
            || in_array($user->role, ['ADMIN', 'SALES_MANAGER', 'SALES_AGENT'], true);
    }

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Grid::make()
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    Select::make('period')
                        ->label('Reporting period')
                        ->options(KpiPeriod::selectOptions())
                        ->default(KpiPeriod::DEFAULT)
                        ->selectablePlaceholder(false)
                        ->live(),

                    DatePicker::make('custom_start')
                        ->label('From')
                        ->visible(fn (Get $get) => $get('period') === 'custom')
                        ->default(now()->startOfMonth())
                        ->live(),

                    DatePicker::make('custom_end')
                        ->label('To')
                        ->visible(fn (Get $get) => $get('period') === 'custom')
                        ->default(now())
                        ->after('custom_start')
                        ->live(),
                ]),
        ]);
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function isManagerOrAdmin(): bool
    {
        $user = auth()->user();
        return (bool) ($user?->is_super_admin
            || in_array($user?->role, ['ADMIN', 'SALES_MANAGER'], true));
    }

    /**
     * Widgets shown on the Management Overview tab.
     * @return array<int, class-string>
     */
    public function getOverviewWidgets(): array
    {
        return [
            \App\Filament\Widgets\Kpi\MoneyFlowStatsWidget::class,
            \App\Filament\Widgets\Kpi\LeadConversionStatsWidget::class,
            \App\Filament\Widgets\Kpi\DepositsByBranchChart::class,
            \App\Filament\Widgets\Kpi\WithdrawalsByBranchChart::class,
            \App\Filament\Widgets\Kpi\NettByBranchChart::class,
            \App\Filament\Widgets\Kpi\ChallengeSalesByBranchChart::class,
        ];
    }

    /**
     * Widgets shown on the Sales Performance tab.
     * @return array<int, class-string>
     */
    public function getSalesWidgets(): array
    {
        return [
            \App\Filament\Widgets\Kpi\LeaderboardTable::class,
        ];
    }
}
