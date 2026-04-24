<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PersonResource\Pages;
use App\Models\Person;
use Filament\Forms\Form;
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
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
            ->defaultSort('created_at', 'desc')
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
        return [];
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
}
