<?php

declare(strict_types=1);

namespace App\Filament\Resources\TradingAccountResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Transactions';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('Date')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->colors([
                        'success' => 'DEPOSIT',
                        'danger'  => 'WITHDRAWAL',
                    ]),

                Tables\Columns\TextColumn::make('amount_usd')
                    ->label('Amount (USD)')
                    ->money('USD')
                    ->sortable(),

                Tables\Columns\TextColumn::make('gateway_name')
                    ->label('Gateway')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'DONE',
                        'warning' => 'PENDING',
                        'danger'  => 'FAILED',
                    ]),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginated([20, 50, 100])
            ->filters([
                SelectFilter::make('type')
                    ->options(['DEPOSIT' => 'Deposits', 'WITHDRAWAL' => 'Withdrawals']),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
