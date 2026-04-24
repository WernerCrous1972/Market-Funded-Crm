<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Activity;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentActivityWidget extends BaseWidget
{
    protected static ?int $sort        = 3;
    protected int | string | array $columnSpan = 'full';
    protected static ?string $heading  = 'Recent Activity';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Activity::with('person')
                    ->orderByDesc('occurred_at')
                    ->limit(20)
            )
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('When')
                    ->since()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->colors([
                        'success' => fn ($state) => in_array($state, ['DEPOSIT', 'TASK_COMPLETED']),
                        'danger'  => fn ($state) => in_array($state, ['WITHDRAWAL']),
                        'warning' => fn ($state) => in_array($state, ['STATUS_CHANGED', 'DUPLICATE_DETECTED']),
                        'primary' => fn ($state) => in_array($state, ['NOTE_ADDED', 'CALL_LOG']),
                    ]),

                Tables\Columns\TextColumn::make('person.full_name')
                    ->label('Contact')
                    ->url(fn (Activity $r) => $r->person_id
                        ? \App\Filament\Resources\PersonResource::getUrl('view', ['record' => $r->person_id])
                        : null
                    ),

                Tables\Columns\TextColumn::make('description')
                    ->wrap()
                    ->limit(80),
            ])
            ->paginated(false);
    }
}
