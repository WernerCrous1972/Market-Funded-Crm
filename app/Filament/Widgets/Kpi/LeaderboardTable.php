<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Kpi;

use App\Services\Kpi\KpiPeriod;
use App\Services\Kpi\KpiQuery;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Hunter / Farmer leaderboard.
 *
 *   - Manager / admin view: all agents, sortable, full names visible.
 *   - Agent view: only the current user's row + the anonymised company
 *     average for comparison (no other agents' names visible).
 *
 * Sort defaults to NETT Deposits descending. Sortable on every column
 * via the $sortColumn / $sortDir public properties (Livewire-driven).
 */
class LeaderboardTable extends Widget
{
    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.kpi.leaderboard-table';

    protected int|string|array $columnSpan = 'full';

    public string $sortColumn = 'nett_deposits_cents';
    public string $sortDir    = 'desc';

    public function sort(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDir = $this->sortDir === 'desc' ? 'asc' : 'desc';
        } else {
            $this->sortColumn = $column;
            $this->sortDir    = 'desc';
        }
    }

    public function getViewData(): array
    {
        $kpi    = app(KpiQuery::class);
        $period = KpiPeriod::fromFilters($this->filters ?? []);
        $user   = auth()->user();

        $isManager = $user?->is_super_admin
            || in_array($user?->role, ['ADMIN', 'SALES_MANAGER'], true);

        if ($isManager) {
            $rows = $this->sortRows($kpi->leaderboard($period));
            return [
                'period'    => $period,
                'isManager' => true,
                'rows'      => $rows,
                'companyAverages' => null,
                'currentUserId'   => $user?->id,
                'sortColumn'      => $this->sortColumn,
                'sortDir'         => $this->sortDir,
            ];
        }

        // Agent view: their own row + company averages, never other names.
        $rows = $kpi->leaderboard($period, $user?->id);
        $rank = $this->computeRank($kpi->leaderboard($period), $user?->id);

        return [
            'period'           => $period,
            'isManager'        => false,
            'rows'             => $rows,
            'companyAverages'  => $kpi->companyAverages($period),
            'currentUserId'    => $user?->id,
            'rank'             => $rank,
            'totalAgents'      => $kpi->leaderboard($period)->count(),
            'sortColumn'       => $this->sortColumn,
            'sortDir'          => $this->sortDir,
        ];
    }

    private function sortRows(Collection $rows): Collection
    {
        $col = $this->sortColumn;
        return $this->sortDir === 'asc'
            ? $rows->sortBy($col)->values()
            : $rows->sortByDesc($col)->values();
    }

    private function computeRank(Collection $allRows, ?string $userId): ?int
    {
        if (! $userId) return null;
        $idx = $allRows->search(fn ($r) => $r->user_id === $userId);
        return $idx === false ? null : ($idx + 1);
    }
}
