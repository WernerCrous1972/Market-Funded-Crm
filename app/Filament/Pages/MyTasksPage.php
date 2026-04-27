<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Personal task queue for the logged-in agent.
 * Admins see all tasks with an assignee filter.
 */
class MyTasksPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon  = 'heroicon-o-check-circle';
    protected static ?string $navigationLabel = 'My Tasks';
    protected static ?string $title           = 'My Tasks';
    protected static ?int    $navigationSort  = 2; // right after Contacts
    protected static string  $view            = 'filament.pages.my-tasks';

    public static function getSlug(): string
    {
        return 'my-tasks';
    }

    // Filter state
    public string $viewMode = 'pending';     // pending | completed | all
    public ?string $filterUserId = null;     // Admin-only: filter by assignee

    public function mount(): void
    {
        // Admins default to seeing all; agents default to their own
        if (auth()->user()?->role === 'ADMIN' || auth()->user()?->role === 'SALES_MANAGER') {
            $this->filterUserId = null; // null = all users
        } else {
            $this->filterUserId = auth()->id();
        }
    }

    // ── Stats for the header ──────────────────────────────────────────────────

    public function getOverdueCount(): int
    {
        return $this->baseQuery()->overdue()->count();
    }

    public function getDueTodayCount(): int
    {
        return $this->baseQuery()->dueToday()->count();
    }

    public function getDueThisWeekCount(): int
    {
        return $this->baseQuery()->dueThisWeek()->count();
    }

    public function getPendingCount(): int
    {
        return $this->baseQuery()->pending()->count();
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query($this->taskQuery())
            ->defaultSort(function ($query) {
                // Sort order: overdue first, then by priority, then by due_at
                $query
                    ->orderByRaw("CASE WHEN completed_at IS NULL AND due_at < NOW() THEN 0 ELSE 1 END ASC")
                    ->orderByRaw("CASE priority WHEN 'URGENT' THEN 0 WHEN 'HIGH' THEN 1 WHEN 'MEDIUM' THEN 2 WHEN 'LOW' THEN 3 ELSE 4 END ASC")
                    ->orderBy('due_at', 'asc');
            })
            ->columns([
                // Status indicator
                Tables\Columns\IconColumn::make('is_overdue')
                    ->label('')
                    ->icon(fn (Task $record) => match (true) {
                        $record->completed_at !== null => 'heroicon-o-check-circle',
                        $record->is_overdue            => 'heroicon-o-exclamation-circle',
                        $record->is_due_today          => 'heroicon-o-clock',
                        default                        => 'heroicon-o-ellipsis-horizontal-circle',
                    })
                    ->color(fn (Task $record) => match (true) {
                        $record->completed_at !== null => 'success',
                        $record->is_overdue            => 'danger',
                        $record->is_due_today          => 'warning',
                        default                        => 'gray',
                    })
                    ->width('40px'),

                Tables\Columns\TextColumn::make('task_type')
                    ->label('')
                    ->formatStateUsing(fn (Task $record) => $record->type_icon)
                    ->width('40px'),

                Tables\Columns\TextColumn::make('title')
                    ->label('Task')
                    ->weight('semibold')
                    ->description(fn (Task $record) => $record->description)
                    ->searchable(),

                Tables\Columns\TextColumn::make('person.full_name')
                    ->label('Contact')
                    ->url(fn (Task $record) => $record->person_id
                        ? route('filament.admin.resources.people.view', $record->person_id)
                        : null
                    )
                    ->color('primary')
                    ->searchable(['people.first_name', 'people.last_name']),

                Tables\Columns\BadgeColumn::make('priority')
                    ->colors([
                        'danger'  => 'URGENT',
                        'warning' => 'HIGH',
                        'info'    => 'MEDIUM',
                        'gray'    => 'LOW',
                    ]),

                Tables\Columns\TextColumn::make('due_at')
                    ->label('Due')
                    ->formatStateUsing(function (?string $state, Task $record) {
                        if (! $record->due_at) return '—';
                        if ($record->is_overdue) return '🔴 ' . $record->due_at->format('d M Y');
                        if ($record->is_due_today) return '🟡 Today ' . $record->due_at->format('H:i');
                        return $record->due_at->format('d M Y');
                    })
                    ->color(fn (Task $record) => match (true) {
                        $record->is_overdue   => 'danger',
                        $record->is_due_today => 'warning',
                        default               => 'gray',
                    })
                    ->sortable(),

                // Admin/manager only: show assignee
                Tables\Columns\TextColumn::make('assignedTo.name')
                    ->label('Assigned To')
                    ->placeholder('Unassigned')
                    ->visible(fn () => in_array(auth()->user()?->role, ['ADMIN', 'SALES_MANAGER']))
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Pending')
                    ->color('success')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('priority')
                    ->options([
                        'URGENT' => '🔴 Urgent',
                        'HIGH'   => '🟠 High',
                        'MEDIUM' => '🟡 Medium',
                        'LOW'    => '⚪ Low',
                    ]),

                Tables\Filters\SelectFilter::make('task_type')
                    ->label('Type')
                    ->options(Task::TYPES),

                // Admin/manager: filter by assignee
                Tables\Filters\SelectFilter::make('assigned_to_user_id')
                    ->label('Assigned To')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                    ->visible(fn () => in_array(auth()->user()?->role, ['ADMIN', 'SALES_MANAGER'])),
            ])
            ->actions([
                // Complete action
                Tables\Actions\Action::make('complete')
                    ->label('Complete')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Task $record) => ! $record->completed_at)
                    ->requiresConfirmation()
                    ->modalHeading('Mark task as complete?')
                    ->modalDescription(fn (Task $record) => $record->title)
                    ->action(function (Task $record) {
                        $record->markComplete(auth()->id());
                        Notification::make()->title('Task completed ✓')->success()->send();
                    }),

                // Reschedule action
                Tables\Actions\Action::make('reschedule')
                    ->label('Reschedule')
                    ->icon('heroicon-o-calendar')
                    ->color('warning')
                    ->visible(fn (Task $record) => ! $record->completed_at)
                    ->form([
                        Forms\Components\DateTimePicker::make('due_at')
                            ->label('New due date')
                            ->native(false)
                            ->default(fn (Task $record) => $record->due_at)
                            ->required(),
                    ])
                    ->action(function (Task $record, array $data) {
                        $record->update(['due_at' => $data['due_at']]);
                        Notification::make()->title('Task rescheduled')->success()->send();
                    }),

                // Reassign action (admin/manager only)
                Tables\Actions\Action::make('reassign')
                    ->label('Reassign')
                    ->icon('heroicon-o-user')
                    ->color('gray')
                    ->visible(fn () => in_array(auth()->user()?->role, ['ADMIN', 'SALES_MANAGER']))
                    ->form([
                        Forms\Components\Select::make('assigned_to_user_id')
                            ->label('Assign to')
                            ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Task $record, array $data) {
                        $record->update([
                            'assigned_to_user_id' => $data['assigned_to_user_id'],
                            'auto_assigned'        => false,
                        ]);
                        Notification::make()->title('Task reassigned')->success()->send();
                    }),

                // View person
                Tables\Actions\Action::make('viewPerson')
                    ->label('View Contact')
                    ->icon('heroicon-o-user')
                    ->color('primary')
                    ->url(fn (Task $record) => $record->person_id
                        ? route('filament.admin.resources.people.view', $record->person_id)
                        : null
                    )
                    ->openUrlInNewTab(),
            ])
            ->headerActions([
                // Quick create task from the task page
                Tables\Actions\Action::make('createTask')
                    ->label('New Task')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form([
                        Forms\Components\Select::make('person_id')
                            ->label('Contact')
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                return \App\Models\Person::where(function ($q) use ($search) {
                                    $q->where('first_name', 'ilike', "%{$search}%")
                                      ->orWhere('last_name', 'ilike', "%{$search}%")
                                      ->orWhere('email', 'ilike', "%{$search}%");
                                })
                                ->limit(20)
                                ->get()
                                ->mapWithKeys(fn ($p) => [$p->id => "{$p->full_name} ({$p->email})"])
                                ->toArray();
                            })
                            ->getOptionLabelUsing(fn ($value) => \App\Models\Person::find($value)?->full_name ?? $value)
                            ->live()
                            ->required(),

                        Forms\Components\TextInput::make('title')
                            ->label('Task')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Follow up re: deposit'),

                        Forms\Components\Select::make('task_type')
                            ->label('Type')
                            ->options(Task::TYPES)
                            ->default(Task::TYPE_GENERAL)
                            ->required(),

                        Forms\Components\Textarea::make('description')
                            ->label('Notes')
                            ->rows(2),

                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DateTimePicker::make('due_at')
                                ->label('Due date')
                                ->native(false)
                                ->minDate(now()),

                            Forms\Components\Select::make('priority')
                                ->options([
                                    'LOW'    => 'Low',
                                    'MEDIUM' => 'Medium',
                                    'HIGH'   => 'High',
                                    'URGENT' => '🔴 Urgent',
                                ])
                                ->default('MEDIUM')
                                ->required(),
                        ]),

                        // Assignee — defaults to account manager, can override
                        Forms\Components\Select::make('assigned_to_user_id')
                            ->label('Assign to')
                            ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->placeholder('Auto-assign to account manager')
                            ->helperText('Leave blank to auto-assign based on account manager'),
                    ])
                    ->action(function (array $data) {
                        $person = \App\Models\Person::find($data['person_id']);

                        // Option C: resolve assignee
                        $assignee = Task::resolveAssignee($person, $data['assigned_to_user_id'] ?? null);

                        // Fall back to current user if no account manager match
                        if (! $assignee['user_id']) {
                            $assignee = ['user_id' => auth()->id(), 'auto_assigned' => false];
                        }

                        $task = Task::create([
                            'person_id'           => $data['person_id'],
                            'assigned_to_user_id' => $assignee['user_id'],
                            'created_by_user_id'  => auth()->id(),
                            'auto_assigned'       => $assignee['auto_assigned'],
                            'task_type'           => $data['task_type'],
                            'title'               => $data['title'],
                            'description'         => $data['description'] ?? null,
                            'due_at'              => $data['due_at'] ?? null,
                            'priority'            => $data['priority'],
                        ]);

                        \App\Models\Activity::record(
                            personId: $data['person_id'],
                            type: \App\Models\Activity::TYPE_TASK_CREATED,
                            description: "Task created: {$data['title']}",
                            userId: auth()->id(),
                        );

                        Notification::make()->title('Task created')->success()->send();
                    }),
            ])
            ->paginated([10, 25, 50])
            ->striped();
    }

    // ── View mode toggle (pending / completed / all) ───────────────────────────

    public function setPendingMode(): void  { $this->viewMode = 'pending'; }
    public function setCompletedMode(): void { $this->viewMode = 'completed'; }
    public function setAllMode(): void       { $this->viewMode = 'all'; }

    // ── Query helpers ─────────────────────────────────────────────────────────

    private function baseQuery(): Builder
    {
        $query = Task::with(['person', 'assignedTo']);

        // Agents only see their own tasks
        if ($this->filterUserId) {
            $query->forUser($this->filterUserId);
        }

        return $query;
    }

    private function taskQuery(): Builder
    {
        $query = $this->baseQuery();

        return match ($this->viewMode) {
            'completed' => $query->completed(),
            'all'       => $query,
            default     => $query->pending(),
        };
    }
}
