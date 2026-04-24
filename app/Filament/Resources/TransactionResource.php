<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Transaction;
use Filament\Forms\Form;
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

                Tables\Columns\BadgeColumn::make('pipeline')
                    ->colors([
                        'primary' => 'MFU_CAPITAL',
                        'success' => 'MFU_MARKETS',
                        'warning' => 'MFU_ACADEMY',
                    ])
                    ->placeholder('—'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->options(['DEPOSIT' => 'Deposits', 'WITHDRAWAL' => 'Withdrawals']),

                SelectFilter::make('status')
                    ->options(['DONE' => 'Done', 'PENDING' => 'Pending', 'FAILED' => 'Failed']),

                SelectFilter::make('pipeline')
                    ->options([
                        'MFU_CAPITAL' => 'MFU Capital',
                        'MFU_ACADEMY' => 'MFU Academy',
                        'MFU_MARKETS' => 'MFU Markets',
                    ]),

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
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
