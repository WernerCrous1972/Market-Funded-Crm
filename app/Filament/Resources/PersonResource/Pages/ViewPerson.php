<?php

declare(strict_types=1);

namespace App\Filament\Resources\PersonResource\Pages;

use App\Filament\Resources\PersonResource;
use App\Filament\Widgets\PersonDepositChartWidget;
use App\Jobs\Metrics\RefreshPersonMetricsJob;
use App\Models\Activity;
use App\Models\Note;
use App\Models\Task;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPerson extends ViewRecord
{
    protected static string $resource = PersonResource::class;

    /**
     * Eager-load everything we need for the detail page.
     * The chart widget reads separately — it doesn't need to be in the infolist record.
     */
    protected function resolveRecord(int | string $key): \App\Models\Person
    {
        return \App\Models\Person::with([
            'metrics',
            'tradingAccounts',
            'transactions' => fn ($q) => $q->orderByDesc('occurred_at')->limit(200),
            'activities'   => fn ($q) => $q->orderByDesc('occurred_at')->limit(100),
            'notes',
            'tasks',
        ])->findOrFail($key);
    }

    /**
     * Inject the chart widget with the current person's ID.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            PersonDepositChartWidget::make(['personId' => $this->record->id]),
        ];
    }

    public function getHeaderWidgetsColumns(): int | array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [

            Actions\Action::make('addNote')
                ->label('Add Note')
                ->icon('heroicon-o-document-plus')
                ->color('info')
                ->form([
                    Forms\Components\TextInput::make('title')
                        ->label('Title')
                        ->placeholder('Optional title')
                        ->maxLength(255),

                    Forms\Components\MarkdownEditor::make('body')
                        ->label('Note')
                        ->required()
                        ->minLength(3)
                        ->toolbarButtons([
                            'bold', 'italic', 'bulletList', 'orderedList', 'link',
                        ]),
                ])
                ->action(function (array $data): void {
                    $person = $this->getRecord();

                    Note::create([
                        'person_id' => $person->id,
                        'user_id'   => auth()->id(),
                        'title'     => $data['title'] ?? null,
                        'body'      => $data['body'],
                        'source'    => 'MANUAL',
                    ]);

                    Activity::record(
                        personId: $person->id,
                        type: Activity::TYPE_NOTE_ADDED,
                        description: 'Note added: ' . ($data['title'] ?? substr($data['body'], 0, 60)),
                        userId: auth()->id(),
                    );

                    Notification::make()->title('Note saved')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $person->id]));
                }),

            Actions\Action::make('createTask')
                ->label('Create Task')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->form([
                    Forms\Components\TextInput::make('title')
                        ->label('Task')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. Follow up re: deposit'),

                    Forms\Components\Select::make('task_type')
                        ->label('Type')
                        ->options(\App\Models\Task::TYPES)
                        ->default(\App\Models\Task::TYPE_GENERAL)
                        ->required(),

                    Forms\Components\Textarea::make('description')
                        ->label('Notes')
                        ->rows(2),

                    Forms\Components\Grid::make(2)
                        ->schema([
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

                    // Option C: manual override, defaults to account manager
                    Forms\Components\Select::make('assigned_to_user_id')
                        ->label('Assign to')
                        ->options(fn () => \App\Models\User::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Auto-assign to account manager')
                        ->helperText('Leave blank to auto-assign based on account manager'),
                ])
                ->action(function (array $data): void {
                    $person = $this->getRecord();

                    // Option C: resolve assignee
                    $assignee = \App\Models\Task::resolveAssignee(
                        $person,
                        $data['assigned_to_user_id'] ?? null
                    );

                    // Fall back to current user if no account manager found
                    if (! $assignee['user_id']) {
                        $assignee = ['user_id' => auth()->id(), 'auto_assigned' => false];
                    }

                    Task::create([
                        'person_id'           => $person->id,
                        'assigned_to_user_id' => $assignee['user_id'],
                        'created_by_user_id'  => auth()->id(),
                        'auto_assigned'       => $assignee['auto_assigned'],
                        'task_type'           => $data['task_type'],
                        'title'               => $data['title'],
                        'description'         => $data['description'] ?? null,
                        'due_at'              => $data['due_at'] ?? null,
                        'priority'            => $data['priority'],
                    ]);

                    Activity::record(
                        personId: $person->id,
                        type: Activity::TYPE_TASK_CREATED,
                        description: "Task created: {$data['title']}",
                        userId: auth()->id(),
                    );

                    Notification::make()->title('Task created')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $person->id]));
                }),

            Actions\Action::make('markContacted')
                ->label(fn () => $this->getRecord()->notes_contacted ? '✔ Contacted' : 'Mark Contacted')
                ->icon('heroicon-o-phone')
                ->color(fn () => $this->getRecord()->notes_contacted ? 'success' : 'gray')
                ->action(function (): void {
                    $person = $this->getRecord();
                    $person->update(['notes_contacted' => ! $person->notes_contacted]);
                    Notification::make()
                        ->title($person->fresh()->notes_contacted ? 'Marked as contacted' : 'Contact flag removed')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('refreshMetrics')
                ->label('Refresh Metrics')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    dispatch(new RefreshPersonMetricsJob($this->getRecord()->id));
                    Notification::make()
                        ->title('Metrics refresh queued')
                        ->body('Financial summary will update shortly.')
                        ->info()
                        ->send();
                }),
        ];
    }

    public function getRelationManagers(): array
    {
        return [];
    }
}
