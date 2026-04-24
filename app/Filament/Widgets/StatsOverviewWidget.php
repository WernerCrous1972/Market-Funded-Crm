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
        $totalPeople  = Person::count();
        $totalLeads   = Person::where('contact_type', 'LEAD')->count();
        $totalClients = Person::where('contact_type', 'CLIENT')->count();

        $newLeadsToday = Person::where('contact_type', 'LEAD')
            ->where('created_at', '>=', today())
            ->count();

        $depositsAllTime = Transaction::deposits()->done()->sum('amount_cents');
        $depositsThisMonth = Transaction::deposits()->done()
            ->where('occurred_at', '>=', now()->startOfMonth())
            ->sum('amount_cents');

        $withdrawalsAllTime = Transaction::withdrawals()->done()->sum('amount_cents');
        $withdrawalsThisMonth = Transaction::withdrawals()->done()
            ->where('occurred_at', '>=', now()->startOfMonth())
            ->sum('amount_cents');

        $newDepositsToday = Transaction::deposits()->done()
            ->where('occurred_at', '>=', today())
            ->count();

        return [
            Stat::make('Total Contacts', number_format($totalPeople))
                ->description("{$totalClients} clients · {$totalLeads} leads")
                ->icon('heroicon-o-users')
                ->color('primary'),

            Stat::make('New Leads Today', number_format($newLeadsToday))
                ->description('First seen in CRM today')
                ->icon('heroicon-o-user-plus')
                ->color('warning'),

            Stat::make('New Deposits Today', number_format($newDepositsToday))
                ->description('Transactions with occurred_at = today')
                ->icon('heroicon-o-arrow-trending-up')
                ->color('success'),

            Stat::make('Deposits This Month', '$' . number_format($depositsThisMonth / 100, 0))
                ->description('All time: $' . number_format($depositsAllTime / 100, 0))
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Withdrawals This Month', '$' . number_format($withdrawalsThisMonth / 100, 0))
                ->description('All time: $' . number_format($withdrawalsAllTime / 100, 0))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('danger'),

            Stat::make('Net Deposits (Month)', '$' . number_format(($depositsThisMonth - $withdrawalsThisMonth) / 100, 0))
                ->icon('heroicon-o-scale')
                ->color('primary'),
        ];
    }
}
