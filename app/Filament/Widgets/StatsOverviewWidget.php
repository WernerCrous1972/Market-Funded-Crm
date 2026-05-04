<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Person;
use App\Models\Transaction;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user              = auth()->user();
        $canViewFinancials = $user?->is_super_admin || $user?->can_view_branch_financials;

        // Personal book scoping: super admins see global totals; everyone else
        // sees only clients where they are the account manager.
        $managerId = $user?->is_super_admin ? null : $user?->id;

        $peopleQuery = fn () => Person::query()
            ->when($managerId, fn ($q) => $q->where('account_manager_user_id', $managerId));

        $txQuery = fn () => Transaction::query()
            ->when($managerId, fn ($q) => $q->whereIn('person_id',
                Person::where('account_manager_user_id', $managerId)->select('id')
            ));

        $totalPeople  = $peopleQuery()->count();
        $totalLeads   = $peopleQuery()->where('contact_type', 'LEAD')->count();
        $totalClients = $peopleQuery()->where('contact_type', 'CLIENT')->count();

        $newLeadsToday = $peopleQuery()
            ->where('contact_type', 'LEAD')
            ->where('created_at', '>=', today())
            ->count();

        $stats = [
            Stat::make('Total Contacts', number_format($totalPeople))
                ->description("{$totalClients} clients · {$totalLeads} leads")
                ->icon('heroicon-o-users')
                ->color('primary'),

            Stat::make('New Leads Today', number_format($newLeadsToday))
                ->description('First seen in CRM today')
                ->icon('heroicon-o-user-plus')
                ->color('warning'),
        ];

        if (! $canViewFinancials) {
            return $stats;
        }

        // ── Financial stats — gated on can_view_branch_financials ────────────
        $extDepAllTime   = $txQuery()->where('category', 'EXTERNAL_DEPOSIT')->sum('amount_cents');
        $extDepMonth     = $txQuery()->where('category', 'EXTERNAL_DEPOSIT')
            ->where('occurred_at', '>=', now()->startOfMonth())->sum('amount_cents');

        $extWdAllTime    = $txQuery()->where('category', 'EXTERNAL_WITHDRAWAL')->sum('amount_cents');
        $extWdMonth      = $txQuery()->where('category', 'EXTERNAL_WITHDRAWAL')
            ->where('occurred_at', '>=', now()->startOfMonth())->sum('amount_cents');

        $challengeAllTime = $txQuery()->where('category', 'CHALLENGE_PURCHASE')->sum('amount_cents');
        $challengeMonth   = $txQuery()->where('category', 'CHALLENGE_PURCHASE')
            ->where('occurred_at', '>=', now()->startOfMonth())->sum('amount_cents');

        $internalMonth   = $txQuery()->where('category', 'INTERNAL_TRANSFER')
            ->where('occurred_at', '>=', now()->startOfMonth())->count();

        $newExtDepToday  = $txQuery()->where('category', 'EXTERNAL_DEPOSIT')
            ->where('occurred_at', '>=', today())->count();

        return array_merge($stats, [
            Stat::make('New Deposits Today', number_format($newExtDepToday))
                ->description('External deposits with occurred_at = today')
                ->icon('heroicon-o-arrow-trending-up')
                ->color('success'),

            Stat::make('Deposits This Month', '$' . number_format($extDepMonth / 100, 0))
                ->description('External only · All time: $' . number_format($extDepAllTime / 100, 0))
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Withdrawals This Month', '$' . number_format($extWdMonth / 100, 0))
                ->description('External only · All time: $' . number_format($extWdAllTime / 100, 0))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('danger'),

            Stat::make('Net Deposits (Month)', '$' . number_format(($extDepMonth - $extWdMonth) / 100, 0))
                ->description('EXTERNAL_DEPOSIT − EXTERNAL_WITHDRAWAL')
                ->icon('heroicon-o-scale')
                ->color('primary'),

            Stat::make('Challenge Sales (Month)', '$' . number_format($challengeMonth / 100, 0))
                ->description('All time: $' . number_format($challengeAllTime / 100, 0))
                ->icon('heroicon-o-trophy')
                ->color('warning'),

            Stat::make('Internal Transfers (Month)', number_format($internalMonth))
                ->description('Wallet movements — not real cashflow')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray'),
        ]);
    }
}
