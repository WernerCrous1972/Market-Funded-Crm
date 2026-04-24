<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PersonResource\Pages;
use App\Filament\Resources\PersonResource\RelationManagers\TradingAccountsRelationManager;
use App\Filament\Resources\PersonResource\RelationManagers\TransactionsRelationManager;
use App\Models\Person;
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

class PersonResource extends Resource
{
    protected static ?string $model = Person::class;

    protected static ?string $navigationIcon  = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Contacts';
    protected static ?string $navigationGroup = 'CRM';
    protected static ?int $navigationSort      = 1;

    public static function form(Form $form): Form
    {
        // Phase 1: read-only. No create/edit form needed yet.
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Contact Details')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('full_name')
                            ->label('Name'),
                        TextEntry::make('contact_type')
                            ->label('Type')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'CLIENT' => 'success',
                                'LEAD'   => 'warning',
                                default  => 'gray',
                            }),
                        TextEntry::make('email')
                            ->copyable()
                            ->icon('heroicon-o-envelope'),
                        TextEntry::make('phone_e164')
                            ->label('Phone')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('country')
                            ->placeholder('—'),
                        TextEntry::make('lead_status')
                            ->label('Lead Status')
                            ->placeholder('—'),
                        TextEntry::make('lead_source')
                            ->label('Lead Source')
                            ->placeholder('—'),
                        TextEntry::make('affiliate')
                            ->placeholder('—'),
                    ]),
                ]),

            Section::make('MTR Details')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('branch')
                            ->placeholder('—'),
                        TextEntry::make('account_manager')
                            ->label('Account Manager')
                            ->placeholder('—'),
                        TextEntry::make('became_active_client_at')
                            ->label('Client Since')
                            ->dateTime('d M Y')
                            ->placeholder('—'),
                        TextEntry::make('last_online_at')
                            ->label('Last Online')
                            ->dateTime('d M Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('mtr_last_synced_at')
                            ->label('Last Synced')
                            ->since()
                            ->placeholder('—'),
                    ]),
                ]),

            Section::make('Activity Timeline')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('mtr_created_at')
                            ->label('MTR Created')
                            ->dateTime('d M Y, H:i')
                            ->placeholder('—'),
                        TextEntry::make('mtr_updated_at')
                            ->label('MTR Updated')
                            ->dateTime('d M Y, H:i')
                            ->placeholder('—'),
                        TextEntry::make('last_external_deposit_at')
                            ->label('Last Deposit')
                            ->state(fn (Person $record): ?string =>
                                $record->last_external_deposit_at?->format('d M Y, H:i')
                            )
                            ->placeholder('—'),
                        TextEntry::make('last_online_at')
                            ->label('Last Online')
                            ->dateTime('d M Y, H:i')
                            ->placeholder('—'),
                        TextEntry::make('days_since_mtr_updated')
                            ->label('Days Since Last Update')
                            ->state(fn (Person $record): string =>
                                self::formatDaysSince($record->mtr_updated_at)
                            )
                            ->color(fn (Person $record): string =>
                                self::daysSinceColor($record->mtr_updated_at)
                            ),
                        TextEntry::make('days_since_last_deposit')
                            ->label('Days Since Last Deposit')
                            ->state(fn (Person $record): string =>
                                self::formatDaysSince($record->last_external_deposit_at)
                            )
                            ->color(fn (Person $record): string =>
                                self::daysSinceColor($record->last_external_deposit_at)
                            ),
                        TextEntry::make('days_since_last_online')
                            ->label('Days Since Last Online')
                            ->state(fn (Person $record): string =>
                                self::formatDaysSince($record->last_online_at)
                            )
                            ->color(fn (Person $record): string =>
                                self::daysSinceColor($record->last_online_at)
                            ),
                    ]),
                ]),

            Section::make('Financials')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('total_deposits')
                            ->label('External Deposits')
                            ->state(fn (Person $record): string =>
                                '$' . number_format($record->total_deposits_cents / 100, 2)
                            ),
                        TextEntry::make('total_withdrawals')
                            ->label('External Withdrawals')
                            ->state(fn (Person $record): string =>
                                '$' . number_format($record->total_withdrawals_cents / 100, 2)
                            ),
                        TextEntry::make('net_deposits')
                            ->label('Net Deposits')
                            ->state(fn (Person $record): string =>
                                '$' . number_format($record->net_deposits_cents / 100, 2)
                            ),
                        TextEntry::make('total_challenge_purchases')
                            ->label('Challenge Purchases')
                            ->state(fn (Person $record): string =>
                                '$' . number_format($record->total_challenge_purchases_cents / 100, 2)
                            ),
                    ]),
                ]),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('mtr_created_at')
                    ->label('MTR Created')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['last_name'])
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-o-envelope'),

                Tables\Columns\TextColumn::make('phone_e164')
                    ->label('Phone')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('country')
                    ->label('Country')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\BadgeColumn::make('contact_type')
                    ->label('Type')
                    ->colors([
                        'success' => 'CLIENT',
                        'warning' => 'LEAD',
                    ]),

                Tables\Columns\TextColumn::make('branch')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('lead_source')
                    ->label('Source')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('account_manager')
                    ->label('AM')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('became_active_client_at')
                    ->label('Client Since')
                    ->date('d M Y')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('mtr_last_synced_at')
                    ->label('Synced')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('mtr_created_at', 'desc')
            ->filters([
                SelectFilter::make('contact_type')
                    ->label('Type')
                    ->options([
                        'LEAD'   => 'Leads',
                        'CLIENT' => 'Clients',
                    ]),

                SelectFilter::make('branch')
                    ->options(
                        \App\Models\Person::distinct()
                            ->pluck('branch', 'branch')
                            ->filter()
                            ->sort()
                            ->toArray()
                    ),

                SelectFilter::make('lead_source')
                    ->label('Lead Source')
                    ->options(
                        \App\Models\Person::distinct()
                            ->pluck('lead_source', 'lead_source')
                            ->filter()
                            ->sort()
                            ->toArray()
                    ),

                SelectFilter::make('pipeline')
                    ->label('Pipeline')
                    ->options([
                        'MFU_CAPITAL'  => 'MFU Capital',
                        'MFU_ACADEMY'  => 'MFU Academy',
                        'MFU_MARKETS'  => 'MFU Markets',
                    ])
                    ->query(fn (Builder $query, array $data) =>
                        $data['value']
                            ? $query->whereHas('tradingAccounts', fn (Builder $q) =>
                                $q->where('pipeline', $data['value'])
                            )
                            : $query
                    ),

                Filter::make('became_client_this_month')
                    ->label('New clients this month')
                    ->query(fn (Builder $query) =>
                        $query->where('contact_type', 'CLIENT')
                            ->where('became_active_client_at', '>=', now()->startOfMonth())
                    ),

                Filter::make('not_contacted')
                    ->label('Not yet contacted')
                    ->query(fn (Builder $query) => $query->where('notes_contacted', false)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->searchPlaceholder('Search name, email or phone…');
    }

    public static function getRelations(): array
    {
        return [
            TradingAccountsRelationManager::class,
            TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPeople::route('/'),
            'view'  => Pages\ViewPerson::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // Phase 1 is read-only
    }

    // ── "Days since" helpers ─────────────────────────────────────────────────

    private static function formatDaysSince(?\Carbon\Carbon $date): string
    {
        if ($date === null) {
            return '—';
        }

        $days = (int) $date->diffInDays(now());

        return match (true) {
            $days === 0 => 'Today',
            $days === 1 => 'Yesterday',
            default     => "{$days} days ago",
        };
    }

    private static function daysSinceColor(?\Carbon\Carbon $date): string
    {
        if ($date === null) {
            return 'gray';
        }

        $days = (int) $date->diffInDays(now());

        return match (true) {
            $days > 30 => 'danger',
            $days > 14 => 'warning',
            default    => 'success',
        };
    }
}
