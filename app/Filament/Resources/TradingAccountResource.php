<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TradingAccountResource\Pages;
use App\Filament\Resources\TradingAccountResource\RelationManagers\TransactionsRelationManager;
use App\Models\TradingAccount;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TradingAccountResource extends Resource
{
    protected static ?string $model = TradingAccount::class;

    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Trading Accounts';
    protected static ?string $navigationGroup = 'CRM';
    protected static ?int $navigationSort      = 2;

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Account Details')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('person.full_name')
                            ->label('Person')
                            ->url(fn (TradingAccount $record): string =>
                                PersonResource::getUrl('view', ['record' => $record->person_id])
                            ),
                        TextEntry::make('mtr_login')
                            ->label('Login')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('offer.name')
                            ->label('Offer')
                            ->placeholder('—'),
                        TextEntry::make('pipeline')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'MFU_CAPITAL' => 'primary',
                                'MFU_ACADEMY' => 'warning',
                                'MFU_MARKETS' => 'success',
                                default        => 'gray',
                            }),
                        IconEntry::make('is_demo')
                            ->label('Demo Account')
                            ->boolean(),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
                        TextEntry::make('opened_at')
                            ->label('Opened')
                            ->date('d M Y')
                            ->placeholder('—'),
                        TextEntry::make('mtr_account_uuid')
                            ->label('MTR Account UUID')
                            ->copyable()
                            ->columnSpanFull(),
                    ]),
                ]),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('opened_at')
                    ->label('Opened')
                    ->date('d M Y')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('person.full_name')
                    ->label('Person')
                    ->searchable(['people.first_name', 'people.last_name'])
                    ->url(fn (TradingAccount $r) =>
                        PersonResource::getUrl('view', ['record' => $r->person_id])
                    ),

                Tables\Columns\TextColumn::make('mtr_login')
                    ->label('Login')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('offer.name')
                    ->label('Offer')
                    ->placeholder('—')
                    ->toggleable(),

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
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('opened_at', 'desc')  // explicit — opened_at now first column
            ->filters([
                SelectFilter::make('pipeline')
                    ->options([
                        'MFU_CAPITAL'  => 'MFU Capital',
                        'MFU_ACADEMY'  => 'MFU Academy',
                        'MFU_MARKETS'  => 'MFU Markets',
                        'UNCLASSIFIED' => 'Unclassified',
                    ]),

                SelectFilter::make('is_demo')
                    ->label('Account type')
                    ->options(['0' => 'Live', '1' => 'Demo']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTradingAccounts::route('/'),
            'view'  => Pages\ViewTradingAccount::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
