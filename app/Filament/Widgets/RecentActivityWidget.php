<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Activity;
use App\Models\Person;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RecentActivityWidget extends BaseWidget
{
    protected static ?int $sort        = 3;
    protected int | string | array $columnSpan = 'full';
    protected static ?string $heading  = 'Recent Activity';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
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

    private function buildQuery(): Builder
    {
        $query = Activity::with('person')
            ->orderByDesc('occurred_at')
            ->limit(20);

        $user = auth()->user();

        if (! $user || $user->is_super_admin) {
            return $query;
        }

        if ($user->assigned_only) {
            // Assigned-only: show activities for their own clients only
            return $query->whereIn('person_id',
                Person::where('account_manager_user_id', $user->id)->select('id')
            );
        }

        $branchIds = DB::table('user_branch_access')
            ->where('user_id', $user->id)
            ->pluck('branch_id')
            ->toArray();

        if (empty($branchIds)) {
            return $query->whereRaw('1 = 0'); // no branch access = no activity
        }

        return $query->whereIn('person_id',
            Person::whereIn('branch_id', $branchIds)->select('id')
        );
    }
}
