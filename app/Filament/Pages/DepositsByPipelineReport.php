<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Report: Deposits by Pipeline by Month
 *
 * Shows EXTERNAL_DEPOSIT totals per pipeline per calendar month.
 */
class DepositsByPipelineReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon  = 'heroicon-o-table-cells';
    protected static ?string $navigationGroup = 'Reports';
    protected static ?string $title           = 'Deposits by Pipeline';
    protected static ?string $navigationLabel = 'Deposits by Pipeline';
    protected static ?int    $navigationSort  = 12;

    protected static string $view = 'filament.pages.deposits-by-pipeline-report';

    protected function getTableQuery(): Builder
    {
        return \App\Models\Transaction::query()
            ->select([
                \DB::raw("TO_CHAR(DATE_TRUNC('month', occurred_at), 'Mon YYYY') AS month_label"),
                \DB::raw("DATE_TRUNC('month', occurred_at) AS month_start"),
                \DB::raw("CONCAT(TO_CHAR(DATE_TRUNC('month', occurred_at), 'YYYY-MM'), '_', pipeline) AS table_record_key"),
                'pipeline',
                \DB::raw('COUNT(*) AS transaction_count'),
                \DB::raw('SUM(amount_cents) AS total_cents'),
            ])
            ->where('category', 'EXTERNAL_DEPOSIT')
            ->where('status', 'DONE')
            ->whereNotNull('pipeline')
            ->groupBy(\DB::raw("DATE_TRUNC('month', occurred_at)"), 'pipeline')
            ->orderByDesc('month_start')
            ->orderBy('pipeline');
    }

    public function getTableRecordKey(\Illuminate\Database\Eloquent\Model $record): string
    {
        return (string) $record->table_record_key;
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('month_label')
                ->label('Month')
                ->weight('semibold'),

            Tables\Columns\BadgeColumn::make('pipeline')
                ->label('Pipeline')
                ->colors([
                    'info'    => 'MFU_MARKETS',
                    'success' => 'MFU_CAPITAL',
                    'warning' => 'MFU_ACADEMY',
                ]),

            Tables\Columns\TextColumn::make('transaction_count')
                ->label('Deposits')
                ->numeric()
                ->alignEnd(),

            Tables\Columns\TextColumn::make('total_cents')
                ->label('Total (USD)')
                ->formatStateUsing(fn ($state) => '$' . number_format($state / 100, 2))
                ->alignEnd()
                ->weight('semibold'),
        ];
    }
}
