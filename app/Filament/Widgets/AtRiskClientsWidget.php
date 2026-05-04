<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Person;
use App\Models\PersonMetric;
use App\Services\Health\HealthScorer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard widget: At-Risk Clients (health score < 40, grade D or F).
 * Shows the 10 worst-scoring clients with quick-link to their detail page.
 */
class AtRiskClientsWidget extends BaseWidget
{
    protected static ?string $heading = '⚠️ At-Risk Clients';

    protected static ?int $sort = 3; // appears after the stats + chart

    protected int | string | array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = auth()->user();
        return $user?->is_super_admin || $user?->can_view_health_scores;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Client')
                    ->weight('semibold')
                    ->url(fn (Person $record) => route('filament.admin.resources.people.view', $record))
                    ->color('primary'),

                Tables\Columns\TextColumn::make('metrics.health_score')
                    ->label('Score')
                    ->alignCenter()
                    ->formatStateUsing(fn (?int $state) => $state ?? '—')
                    ->color(fn (?int $state) => match (true) {
                        $state === null  => 'gray',
                        $state < 35      => 'danger',
                        $state < 40      => 'warning',
                        default          => 'gray',
                    })
                    ->weight('bold'),

                Tables\Columns\BadgeColumn::make('metrics.health_grade')
                    ->label('Grade')
                    ->colors([
                        'danger'  => 'F',
                        'warning' => 'D',
                    ])
                    ->formatStateUsing(fn (?string $state) => $state
                        ? $state . ' — ' . HealthScorer::gradeLabel($state)
                        : '—'
                    ),

                Tables\Columns\TextColumn::make('metrics.days_since_last_login')
                    ->label('Last Login')
                    ->formatStateUsing(fn (?int $state) => $state === null ? 'Never' : "{$state}d ago")
                    ->color(fn (?int $state) => $state === null || $state > 14 ? 'danger' : 'warning'),

                Tables\Columns\TextColumn::make('metrics.days_since_last_deposit')
                    ->label('Last Deposit')
                    ->formatStateUsing(fn (?int $state) => $state === null ? 'Never' : "{$state}d ago")
                    ->color(fn (?int $state) => $state === null || $state > 30 ? 'danger' : 'warning'),

                Tables\Columns\TextColumn::make('metrics.net_deposits_cents')
                    ->label('Net Deposits')
                    ->formatStateUsing(fn (?int $state) => '$' . number_format(($state ?? 0) / 100, 0))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('account_manager')
                    ->label('Manager')
                    ->placeholder('Unassigned')
                    ->color('gray'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Person $record) => route('filament.admin.resources.people.view', $record)),
            ])
            ->paginated(false)
            ->striped();
    }

    private function buildQuery(): Builder
    {
        $query = Person::query()
            ->where('contact_type', 'CLIENT')
            ->whereIn('id', PersonMetric::whereNotNull('health_score')
                ->where('health_score', '<', 40)
                ->select('person_id'))
            ->with('metrics')
            ->orderBy(
                PersonMetric::select('health_score')
                    ->whereColumn('person_id', 'people.id')
                    ->limit(1),
                'asc'
            )
            ->limit(10);

        $user = auth()->user();

        if (! $user || $user->is_super_admin) {
            return $query;
        }

        return $query->where('account_manager_user_id', $user->id);
    }
}
