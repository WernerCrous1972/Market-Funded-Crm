<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Person;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Report: Lead Source Conversion Rates
 *
 * Shows each lead source with: total leads, total clients, conversion rate,
 * avg days to first deposit.
 */
class LeadConversionReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon  = 'heroicon-o-funnel';
    protected static ?string $navigationGroup = 'Reports';
    protected static ?string $title           = 'Lead Conversion Rates';
    protected static ?string $navigationLabel = 'Lead Conversion';
    protected static ?int    $navigationSort  = 11;

    protected static string $view = 'filament.pages.lead-conversion-report';

    protected function getTableQuery(): Builder
    {
        return Person::query()
            ->select([
                'lead_source',
                \DB::raw('lead_source AS table_record_key'),
                \DB::raw('COUNT(*) AS total_leads'),
                \DB::raw("COUNT(*) FILTER (WHERE contact_type = 'CLIENT') AS total_clients"),
                \DB::raw(
                    "ROUND(
                        COUNT(*) FILTER (WHERE contact_type = 'CLIENT')::numeric /
                        NULLIF(COUNT(*), 0) * 100,
                        1
                    ) AS conversion_rate"
                ),
                \DB::raw(
                    "ROUND(
                        AVG(
                            EXTRACT(EPOCH FROM (pm.first_deposit_at - people.mtr_created_at)) / 86400.0
                        ) FILTER (WHERE pm.first_deposit_at IS NOT NULL),
                        1
                    ) AS avg_days_to_first_deposit"
                ),
                \DB::raw('COALESCE(SUM(pm.total_deposits_cents), 0) AS total_deposits_cents'),
            ])
            ->leftJoin('person_metrics AS pm', 'pm.person_id', '=', 'people.id')
            ->whereNotNull('lead_source')
            ->where('lead_source', '!=', '')
            ->groupBy('lead_source')
            ->orderByDesc('total_leads');
    }

    public function getTableRecordKey(\Illuminate\Database\Eloquent\Model $record): string
    {
        return (string) $record->lead_source;
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('lead_source')
                ->label('Lead Source')
                ->weight('semibold')
                ->searchable(),

            Tables\Columns\TextColumn::make('total_leads')
                ->label('Total Leads')
                ->numeric()
                ->sortable()
                ->alignEnd(),

            Tables\Columns\TextColumn::make('total_clients')
                ->label('Converted')
                ->numeric()
                ->sortable()
                ->alignEnd(),

            Tables\Columns\TextColumn::make('conversion_rate')
                ->label('Conversion %')
                ->formatStateUsing(fn ($state) => $state ? "{$state}%" : '0%')
                ->sortable()
                ->alignEnd()
                ->color(fn ($state) => match (true) {
                    ($state ?? 0) >= 30 => 'success',
                    ($state ?? 0) >= 10 => 'warning',
                    default             => 'danger',
                }),

            Tables\Columns\TextColumn::make('avg_days_to_first_deposit')
                ->label('Avg Days to Deposit')
                ->formatStateUsing(fn ($state) => $state ? "{$state}d" : '—')
                ->sortable()
                ->alignEnd(),

            Tables\Columns\TextColumn::make('total_deposits_cents')
                ->label('Total Deposits')
                ->formatStateUsing(fn ($state) => '$' . number_format($state / 100, 2))
                ->sortable()
                ->alignEnd(),
        ];
    }
}
