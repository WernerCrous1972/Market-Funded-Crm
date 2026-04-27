<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Transaction;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Report: Top IB Partners by Volume
 *
 * Shows affiliates ranked by total external deposit volume.
 */
class TopIbPartnersReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Reports';
    protected static ?string $title           = 'Top IB Partners';
    protected static ?string $navigationLabel = 'Top IB Partners';
    protected static ?int    $navigationSort  = 10;

    protected static string $view = 'filament.pages.top-ib-partners-report';

    protected function getTableQuery(): Builder
    {
        // We query people grouped by affiliate, joining person_metrics for totals
        return \App\Models\Person::query()
            ->select([
                'affiliate',
                \DB::raw('affiliate AS table_record_key'),
                \DB::raw('COUNT(DISTINCT people.id) AS client_count'),
                \DB::raw('COALESCE(SUM(pm.total_deposits_cents), 0) AS total_deposits_cents'),
                \DB::raw('COALESCE(SUM(pm.net_deposits_cents), 0) AS net_deposits_cents'),
                \DB::raw('COALESCE(SUM(pm.total_challenge_purchases_cents), 0) AS challenge_purchases_cents'),
            ])
            ->leftJoin('person_metrics AS pm', 'pm.person_id', '=', 'people.id')
            ->whereNotNull('affiliate')
            ->where('affiliate', '!=', '')
            ->groupBy('affiliate')
            ->orderByDesc('total_deposits_cents');
    }

    public function getTableRecordKey(\Illuminate\Database\Eloquent\Model $record): string
    {
        return (string) $record->affiliate;
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('affiliate')
                ->label('IB Partner')
                ->searchable()
                ->sortable()
                ->weight('semibold'),

            Tables\Columns\TextColumn::make('client_count')
                ->label('Clients')
                ->numeric()
                ->sortable()
                ->alignEnd(),

            Tables\Columns\TextColumn::make('total_deposits_cents')
                ->label('Total Deposits')
                ->formatStateUsing(fn ($state) => '$' . number_format($state / 100, 2))
                ->sortable()
                ->alignEnd(),

            Tables\Columns\TextColumn::make('net_deposits_cents')
                ->label('Net Deposits')
                ->formatStateUsing(fn ($state) => '$' . number_format($state / 100, 2))
                ->sortable()
                ->alignEnd(),

            Tables\Columns\TextColumn::make('challenge_purchases_cents')
                ->label('Challenge Sales')
                ->formatStateUsing(fn ($state) => '$' . number_format($state / 100, 2))
                ->sortable()
                ->alignEnd(),
        ];
    }
}
