<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PersonResource\Pages;
use App\Helpers\CountryHelper;
use App\Models\Person;
use App\Models\PersonMetric;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PersonResource extends Resource
{
    protected static ?string $model = Person::class;
    protected static ?string $navigationIcon    = 'heroicon-o-users';
    protected static ?string $navigationLabel   = 'Contacts';
    protected static ?string $modelLabel        = 'Person';
    protected static ?string $pluralModelLabel  = 'People';
    protected static ?int    $navigationSort    = 1;

    // ── List table ───────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('mtr_created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('mtr_created_at')
                    ->label('MTR Created')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name'])
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->limit(35),

                Tables\Columns\TextColumn::make('phone_e164')
                    ->label('Phone')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('contact_type')
                    ->label('Type')
                    ->colors([
                        'success' => 'CLIENT',
                        'warning' => 'LEAD',
                    ]),

                Tables\Columns\TextColumn::make('country')
                    ->label('Country')
                    ->formatStateUsing(fn (?string $state) => CountryHelper::display($state))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('branch')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('lead_source')
                    ->label('Source')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('metrics.total_deposits_cents')
                    ->label('Total Deposits')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? '$' . number_format($state / 100, 2) : '—')
                    ->sortable()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('metrics.net_deposits_cents')
                    ->label('Net Deposits')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? '$' . number_format($state / 100, 2) : '—')
                    ->sortable()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('metrics.last_deposit_at')
                    ->label('Last Deposit')
                    ->since()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('became_active_client_at')
                    ->label('Client Since')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('contact_type')
                    ->label('Type')
                    ->options([
                        'LEAD'   => 'Lead',
                        'CLIENT' => 'Client',
                    ]),

                Tables\Filters\SelectFilter::make('branch')
                    ->options(fn () => Person::distinct()->pluck('branch', 'branch')->filter()->toArray())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('lead_source')
                    ->label('Lead Source')
                    ->options(fn () => Person::distinct()->pluck('lead_source', 'lead_source')->filter()->toArray())
                    ->searchable(),

                // Pipeline filter via metrics flags (fast — no join needed)
                Tables\Filters\Filter::make('has_markets')
                    ->label('MFU Markets')
                    ->query(fn (Builder $q) => $q->whereHas('metrics', fn ($m) => $m->where('has_markets', true))),

                Tables\Filters\Filter::make('has_capital')
                    ->label('MFU Capital')
                    ->query(fn (Builder $q) => $q->whereHas('metrics', fn ($m) => $m->where('has_capital', true))),

                Tables\Filters\Filter::make('has_academy')
                    ->label('MFU Academy')
                    ->query(fn (Builder $q) => $q->whereHas('metrics', fn ($m) => $m->where('has_academy', true))),

                // ── Operational saved filters ──────────────────────────────

                Tables\Filters\Filter::make('inactive_traders')
                    ->label('📉 Dropped volume (30d)')
                    ->query(fn (Builder $q) => $q
                        ->where('contact_type', 'CLIENT')
                        ->whereHas('metrics', fn ($m) => $m
                            ->where('days_since_last_deposit', '>', 30)
                            ->where('total_deposits_cents', '>', 0)
                        )
                    ),

                Tables\Filters\Filter::make('unconverted_leads')
                    ->label('⏳ Unconverted 7d+')
                    ->query(fn (Builder $q) => $q
                        ->where('contact_type', 'LEAD')
                        ->where('mtr_created_at', '<', now()->subDays(7))
                        ->whereHas('metrics', fn ($m) => $m->where('deposit_count', 0))
                    ),

                Tables\Filters\Filter::make('dormant_with_equity')
                    ->label('💤 Dormant (10d+ no login)')
                    ->query(fn (Builder $q) => $q
                        ->where('contact_type', 'CLIENT')
                        ->whereHas('metrics', fn ($m) => $m
                            ->where('days_since_last_login', '>', 10)
                            ->where('net_deposits_cents', '>', 500_000) // > $5,000
                        )
                    ),

                Tables\Filters\Filter::make('new_this_month')
                    ->label('🆕 New this month')
                    ->query(fn (Builder $q) => $q->where('mtr_created_at', '>=', now()->startOfMonth())),

                Tables\Filters\Filter::make('not_contacted')
                    ->label('📵 Not contacted')
                    ->query(fn (Builder $q) => $q->where('notes_contacted', false)),
            ])
            ->filtersLayout(Tables\Enums\FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    // ── Infolist (detail view) ────────────────────────────────────────────────

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                // Header section
                Infolists\Components\Section::make()
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\Group::make([
                                    Infolists\Components\TextEntry::make('full_name')
                                        ->label('')
                                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                                        ->weight('bold'),

                                    Infolists\Components\TextEntry::make('email')
                                        ->label('')
                                        ->copyable()
                                        ->icon('heroicon-o-envelope'),

                                    // Phone with WhatsApp link
                                    Infolists\Components\TextEntry::make('phone_e164')
                                        ->label('')
                                        ->icon('heroicon-o-phone')
                                        ->formatStateUsing(function (Person $record) {
                                            if (! $record->phone_e164) return '—';
                                            $wa = $record->whatsapp_link;
                                            if ($wa) {
                                                return "<a href=\"{$wa}\" target=\"_blank\" class=\"text-green-600 hover:underline\">{$record->phone_e164} 💬</a>";
                                            }
                                            return $record->phone_e164;
                                        })
                                        ->html(),
                                ])->columnSpan(2),

                                Infolists\Components\Group::make([
                                    Infolists\Components\TextEntry::make('country_display')
                                        ->label('Country')
                                        ->icon('heroicon-o-globe-alt'),

                                    Infolists\Components\TextEntry::make('contact_type')
                                        ->label('Type')
                                        ->badge()
                                        ->color(fn (string $state) => match ($state) {
                                            'CLIENT' => 'success',
                                            'LEAD'   => 'warning',
                                            default  => 'gray',
                                        }),

                                    Infolists\Components\TextEntry::make('branch')
                                        ->label('Branch'),
                                ])->columnSpan(1),

                                Infolists\Components\Group::make([
                                    Infolists\Components\TextEntry::make('account_manager')
                                        ->label('Account Manager')
                                        ->icon('heroicon-o-user-circle')
                                        ->placeholder('Unassigned'),

                                    Infolists\Components\TextEntry::make('lead_source')
                                        ->label('Lead Source')
                                        ->placeholder('—'),

                                    Infolists\Components\TextEntry::make('lead_status')
                                        ->label('Lead Status')
                                        ->placeholder('—'),
                                ])->columnSpan(1),
                            ]),

                        // Pipeline pills
                        Infolists\Components\TextEntry::make('pipelines')
                            ->label('Segments')
                            ->badge()
                            ->separator(',')
                            ->formatStateUsing(fn (string $state) => match ($state) {
                                'MFU_MARKETS' => '📈 MFU Markets',
                                'MFU_CAPITAL' => '🏆 MFU Capital',
                                'MFU_ACADEMY' => '🎓 MFU Academy',
                                default        => $state,
                            })
                            ->color(fn (string $state) => match ($state) {
                                'MFU_MARKETS' => 'info',
                                'MFU_CAPITAL' => 'success',
                                'MFU_ACADEMY' => 'warning',
                                default        => 'gray',
                            }),
                    ]),

                // Key stats row
                Infolists\Components\Section::make('Financial Summary')
                    ->schema([
                        Infolists\Components\Grid::make(6)
                            ->schema([
                                Infolists\Components\TextEntry::make('metrics.total_deposits_cents')
                                    ->label('Total Deposits')
                                    ->formatStateUsing(fn (?int $state) => '$' . number_format(($state ?? 0) / 100, 2))
                                    ->icon('heroicon-o-arrow-down-circle')
                                    ->iconColor('success'),

                                Infolists\Components\TextEntry::make('metrics.total_withdrawals_cents')
                                    ->label('Total Withdrawals')
                                    ->formatStateUsing(fn (?int $state) => '$' . number_format(($state ?? 0) / 100, 2))
                                    ->icon('heroicon-o-arrow-up-circle')
                                    ->iconColor('danger'),

                                Infolists\Components\TextEntry::make('metrics.net_deposits_cents')
                                    ->label('Net Deposits')
                                    ->formatStateUsing(function (?int $state) {
                                        $val = ($state ?? 0) / 100;
                                        $fmt = '$' . number_format(abs($val), 2);
                                        return $val < 0 ? "-{$fmt}" : $fmt;
                                    })
                                    ->icon('heroicon-o-banknotes'),

                                Infolists\Components\TextEntry::make('metrics.total_challenge_purchases_cents')
                                    ->label('Challenge Purchases')
                                    ->formatStateUsing(fn (?int $state) => '$' . number_format(($state ?? 0) / 100, 2))
                                    ->icon('heroicon-o-trophy')
                                    ->iconColor('warning'),

                                Infolists\Components\TextEntry::make('metrics.days_since_last_deposit')
                                    ->label('Days Since Deposit')
                                    ->formatStateUsing(fn (?int $state) => $state !== null ? "{$state}d ago" : 'Never')
                                    ->icon('heroicon-o-calendar')
                                    ->color(fn (?int $state) => match (true) {
                                        $state === null    => 'gray',
                                        $state > 30        => 'danger',
                                        $state > 14        => 'warning',
                                        default            => 'success',
                                    }),

                                Infolists\Components\TextEntry::make('metrics.days_since_last_login')
                                    ->label('Days Since Login')
                                    ->formatStateUsing(fn (?int $state) => $state !== null ? "{$state}d ago" : 'Never')
                                    ->icon('heroicon-o-clock')
                                    ->color(fn (?int $state) => match (true) {
                                        $state === null    => 'gray',
                                        $state > 14        => 'danger',
                                        $state > 7         => 'warning',
                                        default            => 'success',
                                    }),
                            ]),
                    ]),

                // Main content — tabs
                Infolists\Components\Tabs::make('PersonTabs')
                    ->tabs([

                        // ── Activity timeline ────────────────────────────────
                        Infolists\Components\Tabs\Tab::make('Activity')
                            ->icon('heroicon-o-clock')
                            ->schema([
                                Infolists\Components\RepeatableEntry::make('activities')
                                    ->label('')
                                    ->schema([
                                        Infolists\Components\Grid::make(12)
                                            ->schema([
                                                Infolists\Components\TextEntry::make('type')
                                                    ->label('')
                                                    ->badge()
                                                    ->columnSpan(2)
                                                    ->color(fn (string $state) => match ($state) {
                                                        'DEPOSIT'          => 'success',
                                                        'WITHDRAWAL'       => 'danger',
                                                        'NOTE_ADDED'       => 'info',
                                                        'TASK_CREATED'     => 'warning',
                                                        'TASK_COMPLETED'   => 'success',
                                                        'STATUS_CHANGED'   => 'primary',
                                                        'EMAIL_SENT'       => 'info',
                                                        'WHATSAPP_SENT'    => 'success',
                                                        'DUPLICATE_DETECTED' => 'danger',
                                                        default            => 'gray',
                                                    })
                                                    ->formatStateUsing(fn (string $state) => match ($state) {
                                                        'DEPOSIT'            => '↓ Deposit',
                                                        'WITHDRAWAL'         => '↑ Withdrawal',
                                                        'NOTE_ADDED'         => '📝 Note',
                                                        'TASK_CREATED'       => '✅ Task',
                                                        'TASK_COMPLETED'     => '✔ Done',
                                                        'STATUS_CHANGED'     => '🔄 Status',
                                                        'EMAIL_SENT'         => '✉ Email',
                                                        'WHATSAPP_SENT'      => '💬 WhatsApp',
                                                        'CALL_LOG'           => '📞 Call',
                                                        'DUPLICATE_DETECTED' => '⚠ Duplicate',
                                                        default              => $state,
                                                    }),

                                                Infolists\Components\TextEntry::make('description')
                                                    ->label('')
                                                    ->columnSpan(8),

                                                Infolists\Components\TextEntry::make('occurred_at')
                                                    ->label('')
                                                    ->dateTime('d M Y H:i')
                                                    ->columnSpan(2)
                                                    ->color('gray'),
                                            ]),
                                    ])
                                    ->contained(false),
                            ]),

                        // ── Transactions ─────────────────────────────────────
                        Infolists\Components\Tabs\Tab::make('Transactions')
                            ->icon('heroicon-o-banknotes')
                            ->schema([
                                Infolists\Components\RepeatableEntry::make('transactions')
                                    ->label('')
                                    ->schema([
                                        Infolists\Components\Grid::make(10)
                                            ->schema([
                                                Infolists\Components\TextEntry::make('occurred_at')
                                                    ->label('Date')
                                                    ->dateTime('d M Y')
                                                    ->columnSpan(2),

                                                Infolists\Components\TextEntry::make('type')
                                                    ->label('Type')
                                                    ->badge()
                                                    ->columnSpan(1)
                                                    ->color(fn (string $state) => $state === 'DEPOSIT' ? 'success' : 'danger'),

                                                Infolists\Components\TextEntry::make('category')
                                                    ->label('Category')
                                                    ->badge()
                                                    ->columnSpan(2)
                                                    ->color(fn (string $state) => match ($state) {
                                                        'EXTERNAL_DEPOSIT'     => 'success',
                                                        'EXTERNAL_WITHDRAWAL'  => 'danger',
                                                        'CHALLENGE_PURCHASE'   => 'warning',
                                                        'INTERNAL_TRANSFER'    => 'gray',
                                                        'CHALLENGE_REFUND'     => 'info',
                                                        default                => 'gray',
                                                    }),

                                                Infolists\Components\TextEntry::make('amount_cents')
                                                    ->label('Amount')
                                                    ->formatStateUsing(fn (int $state) => '$' . number_format($state / 100, 2))
                                                    ->columnSpan(2)
                                                    ->alignEnd()
                                                    ->weight('semibold'),

                                                Infolists\Components\TextEntry::make('gateway_name')
                                                    ->label('Gateway')
                                                    ->columnSpan(3)
                                                    ->color('gray'),
                                            ]),
                                    ])
                                    ->contained(false),
                            ]),

                        // ── Notes ─────────────────────────────────────────────
                        Infolists\Components\Tabs\Tab::make('Notes')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Infolists\Components\RepeatableEntry::make('notes')
                                    ->label('')
                                    ->schema([
                                        Infolists\Components\Grid::make(12)
                                            ->schema([
                                                Infolists\Components\TextEntry::make('created_at')
                                                    ->label('')
                                                    ->dateTime('d M Y H:i')
                                                    ->columnSpan(2)
                                                    ->color('gray'),

                                                Infolists\Components\TextEntry::make('title')
                                                    ->label('')
                                                    ->weight('semibold')
                                                    ->placeholder('Untitled')
                                                    ->columnSpan(10),

                                                Infolists\Components\TextEntry::make('body')
                                                    ->label('')
                                                    ->markdown()
                                                    ->columnSpan(12),
                                            ]),
                                    ])
                                    ->contained(false),
                            ]),

                        // ── Tasks ─────────────────────────────────────────────
                        Infolists\Components\Tabs\Tab::make('Tasks')
                            ->icon('heroicon-o-check-circle')
                            ->schema([
                                Infolists\Components\RepeatableEntry::make('tasks')
                                    ->label('')
                                    ->schema([
                                        Infolists\Components\Grid::make(12)
                                            ->schema([
                                                Infolists\Components\TextEntry::make('priority')
                                                    ->label('')
                                                    ->badge()
                                                    ->columnSpan(1)
                                                    ->color(fn (string $state) => match ($state) {
                                                        'URGENT' => 'danger',
                                                        'HIGH'   => 'warning',
                                                        'MEDIUM' => 'info',
                                                        'LOW'    => 'gray',
                                                        default  => 'gray',
                                                    }),

                                                Infolists\Components\TextEntry::make('title')
                                                    ->label('')
                                                    ->weight('semibold')
                                                    ->columnSpan(6),

                                                Infolists\Components\TextEntry::make('due_at')
                                                    ->label('Due')
                                                    ->dateTime('d M Y')
                                                    ->columnSpan(2)
                                                    ->color(fn ($record) => $record?->due_at?->isPast() && ! $record->completed_at
                                                        ? 'danger' : 'gray'),

                                                Infolists\Components\TextEntry::make('completed_at')
                                                    ->label('Status')
                                                    ->formatStateUsing(fn ($state) => $state ? '✔ Done' : 'Pending')
                                                    ->columnSpan(2)
                                                    ->color(fn ($state) => $state ? 'success' : 'warning'),

                                                Infolists\Components\TextEntry::make('description')
                                                    ->label('')
                                                    ->columnSpan(12)
                                                    ->color('gray')
                                                    ->placeholder(''),
                                            ]),
                                    ])
                                    ->contained(false),
                            ]),

                        // ── Trading Accounts ──────────────────────────────────
                        Infolists\Components\Tabs\Tab::make('Trading Accounts')
                            ->icon('heroicon-o-chart-bar')
                            ->schema([
                                Infolists\Components\RepeatableEntry::make('tradingAccounts')
                                    ->label('')
                                    ->schema([
                                        Infolists\Components\Grid::make(8)
                                            ->schema([
                                                Infolists\Components\TextEntry::make('mtr_login')
                                                    ->label('Login')
                                                    ->weight('semibold')
                                                    ->columnSpan(1),

                                                Infolists\Components\TextEntry::make('pipeline')
                                                    ->label('Pipeline')
                                                    ->badge()
                                                    ->columnSpan(2)
                                                    ->color(fn (string $state) => match ($state) {
                                                        'MFU_MARKETS' => 'info',
                                                        'MFU_CAPITAL' => 'success',
                                                        'MFU_ACADEMY' => 'warning',
                                                        default        => 'gray',
                                                    }),

                                                Infolists\Components\TextEntry::make('is_demo')
                                                    ->label('Mode')
                                                    ->formatStateUsing(fn (bool $state) => $state ? 'Demo' : 'Live')
                                                    ->badge()
                                                    ->columnSpan(1)
                                                    ->color(fn (bool $state) => $state ? 'gray' : 'success'),

                                                Infolists\Components\TextEntry::make('is_active')
                                                    ->label('Status')
                                                    ->formatStateUsing(fn (bool $state) => $state ? 'Active' : 'Inactive')
                                                    ->badge()
                                                    ->columnSpan(1)
                                                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),

                                                Infolists\Components\TextEntry::make('opened_at')
                                                    ->label('Opened')
                                                    ->date('d M Y')
                                                    ->columnSpan(2),
                                            ]),
                                    ])
                                    ->contained(false),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    // ── Form (create/edit — disabled) ─────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    // ── Pages ────────────────────────────────────────────────────────────────

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPeople::route('/'),
            'view'  => Pages\ViewPerson::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
