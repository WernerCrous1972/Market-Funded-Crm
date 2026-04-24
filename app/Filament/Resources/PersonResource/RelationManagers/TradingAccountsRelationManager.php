<?php

declare(strict_types=1);

namespace App\Filament\Resources\PersonResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TradingAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'tradingAccounts';

    protected static ?string $title = 'Trading Accounts';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('mtr_login')
                    ->label('Login')
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('offer.name')
                    ->label('Offer')
                    ->placeholder('—'),

                Tables\Columns\BadgeColumn::make('pipeline')
                    ->colors([
                        'primary' => 'MFU_CAPITAL',
                        'success' => 'MFU_MARKETS',
                        'warning' => 'MFU_ACADEMY',
                        'gray'    => 'UNCLASSIFIED',
                    ]),

                Tables\Columns\IconColumn::make('is_demo')
                    ->label('Demo')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('opened_at')
                    ->label('Opened')
                    ->date('d M Y')
                    ->placeholder('—'),
            ])
            ->defaultSort('opened_at', 'desc')
            ->paginated([10, 25, 50])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
