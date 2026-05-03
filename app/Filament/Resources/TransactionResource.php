<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Person;
use App\Models\Transaction;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Transactions';
    protected static ?string $navigationGroup = 'CRM';
    protected static ?int $navigationSort      = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Transaction Details')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('person.full_name')
                            ->label('Person')
                            ->url(fn (Transaction $record): string =>
                                PersonResource::getUrl('view', ['record' => $record->person_id])
                            ),
                        TextEntry::make('type')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'DEPOSIT'    => 'success',
                                'WITHDRAWAL' => 'danger',
                                default      => 'gray',
                            }),
                        TextEntry::make('amount_usd')
                            ->label('Amount (USD)')
                            ->money('USD'),
                        TextEntry::make('currency'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'DONE'    => 'success',
                                'PENDING' => 'warning',
                                'FAILED'  => 'danger',
                                default   => 'gray',
                            }),
                        TextEntry::make('category')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'EXTERNAL_DEPOSIT'    => 'success',
                                'EXTERNAL_WITHDRAWAL' => 'danger',
                                'CHALLENGE_PURCHASE'  => 'primary',
                                'CHALLENGE_REFUND'    => 'warning',
                                'INTERNAL_TRANSFER'   => 'gray',
                                default               => 'gray',
                            }),
                        TextEntry::make('pipeline')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'MFU_CAPITAL' => 'primary',
                                'MFU_ACADEMY' => 'warning',
                                'MFU_MARKETS' => 'success',
                                default        => 'gray',
                            })
                            ->placeholder('—'),
                        TextEntry::make('gateway_name')
                            ->label('Gateway')
                            ->placeholder('—'),
                        TextEntry::make('remark')
                            ->placeholder('—'),
                        TextEntry::make('occurred_at')
                            ->label('Occurred At')
                            ->dateTime('d M Y H:i'),
                        TextEntry::make('synced_at')
                            ->label('Synced At')
                            ->dateTime('d M Y H:i'),
                        TextEntry::make('mtr_transaction_uuid')
                            ->label('MTR Transaction UUID')
                            ->copyable()
                            ->columnSpanFull(),
                    ]),
                ]),

            Section::make('Trading Account')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('tradingAccount.mtr_login')
                            ->label('Login')
                            ->placeholder('—'),
                        TextEntry::make('tradingAccount.pipeline')
                            ->label('Pipeline')
                            ->badge()
                            ->color(fn (?string $state) => match ($state) {
                                'MFU_CAPITAL' => 'primary',
                                'MFU_ACADEMY' => 'warning',
                                'MFU_MARKETS' => 'success',
                                default        => 'gray',
                            })
                            ->placeholder('—'),
                    ]),
                ])
                ->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('Date')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('person.full_name')
                    ->label('Person')
                    ->searchable(['people.first_name', 'people.last_name'])
                    ->url(fn (Transaction $r) =>
                        PersonResource::getUrl('view', ['record' => $r->person_id])
                    ),

                Tables\Columns\TextColumn::make('person.branch')
                    ->label('Branch')
                    ->sortable(query: fn (Builder $query, string $direction) =>
                        $query->join('people', 'transactions.person_id', '=', 'people.id')
                              ->orderBy('people.branch', $direction)
                    )
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->colors(['success' => 'DONE', 'warning' => 'PENDING', 'danger' => 'FAILED']),

                Tables\Columns\BadgeColumn::make('category')
                    ->colors([
                        'success' => 'EXTERNAL_DEPOSIT',
                        'danger'  => 'EXTERNAL_WITHDRAWAL',
                        'primary' => 'CHALLENGE_PURCHASE',
                        'warning' => 'CHALLENGE_REFUND',
                        'gray'    => 'INTERNAL_TRANSFER',
                    ]),

                Tables\Columns\BadgeColumn::make('pipeline')
                    ->colors([
                        'primary' => 'MFU_CAPITAL',
                        'success' => 'MFU_MARKETS',
                        'warning' => 'MFU_ACADEMY',
                    ])
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->options(['DEPOSIT' => 'Deposits', 'WITHDRAWAL' => 'Withdrawals']),

                SelectFilter::make('status')
                    ->options(['DONE' => 'Done', 'PENDING' => 'Pending', 'FAILED' => 'Failed']),

                SelectFilter::make('category')
                    ->label('Category')
                    ->options([
                        'EXTERNAL_DEPOSIT'    => 'External Deposit',
                        'EXTERNAL_WITHDRAWAL' => 'External Withdrawal',
                        'CHALLENGE_PURCHASE'  => 'Challenge Purchase',
                        'CHALLENGE_REFUND'    => 'Challenge Refund',
                        'INTERNAL_TRANSFER'   => 'Internal Transfer',
                        'UNCLASSIFIED'        => 'Unclassified',
                    ]),

                SelectFilter::make('pipeline')
                    ->options([
                        'MFU_CAPITAL' => 'MFU Capital',
                        'MFU_ACADEMY' => 'MFU Academy',
                        'MFU_MARKETS' => 'MFU Markets',
                    ]),

                SelectFilter::make('branch')
                    ->label('Branch')
                    ->options(fn () => Person::distinct()->pluck('branch', 'branch')->filter()->sort()->toArray())
                    ->query(fn (Builder $q, array $data) =>
                        $data['value']
                            ? $q->whereIn('person_id', Person::where('branch', $data['value'])->select('id'))
                            : $q
                    )
                    ->searchable(),

                Filter::make('this_month')
                    ->label('This month')
                    ->query(fn (Builder $q) => $q->where('occurred_at', '>=', now()->startOfMonth())),

                Filter::make('large_deposits')
                    ->label('Large deposits ($5k+)')
                    ->query(fn (Builder $q) =>
                        $q->where('type', 'DEPOSIT')->where('amount_cents', '>=', 500000)
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
            'view'  => Pages\ViewTransaction::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
