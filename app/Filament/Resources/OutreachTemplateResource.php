<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OutreachTemplateResource\Pages;
use App\Models\AiDraft;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Services\AI\AiOrchestratorException;
use App\Services\AI\OutreachOrchestrator;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Admin CRUD for AI outreach templates.
 *
 * The autonomous-enable toggle is gated behind a confirmation modal —
 * flipping it to true means the system will start generating + sending
 * drafts for this trigger event without human review. Cost ceilings,
 * compliance gate, kill switch all still apply, but it's a meaningful
 * change of state and should look intentional.
 */
class OutreachTemplateResource extends Resource
{
    protected static ?string $model           = OutreachTemplate::class;
    protected static ?string $navigationIcon  = 'heroicon-o-sparkles';
    protected static ?string $navigationLabel = 'AI Templates';
    protected static ?string $navigationGroup = 'AI Outreach';
    protected static ?int    $navigationSort  = 10;

    public static function canViewAny(): bool
    {
        return auth()->user()?->is_super_admin === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Template name')
                    ->placeholder('e.g. "Welcome — new lead"')
                    ->required()
                    ->maxLength(100)
                    ->columnSpanFull(),

                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\Select::make('trigger_event')
                        ->label('Trigger event')
                        ->options([
                            'lead_created'        => 'New lead arrives',
                            'deposit_first'       => 'First deposit landed',
                            'challenge_purchased' => 'Challenge purchased',
                            'challenge_passed'    => 'Challenge passed (Phase 4.5)',
                            'challenge_failed'    => 'Challenge failed (Phase 4.5)',
                            'course_purchased'    => 'Academy course purchased',
                            'dormant_14d'         => 'Dormant 14 days',
                            'dormant_30d'         => 'Dormant 30 days',
                            'large_withdrawal'    => 'Large withdrawal',
                        ])
                        ->placeholder('Manual only — no event'),

                    Forms\Components\Select::make('channel')
                        ->options([
                            'WHATSAPP' => 'WhatsApp',
                            'EMAIL'    => 'Email',
                        ])
                        ->default('WHATSAPP')
                        ->required(),

                    Forms\Components\Select::make('model_preference')
                        ->label('Model override (optional)')
                        ->options([
                            'claude-sonnet-4-6'         => 'Claude Sonnet 4.6 (quality)',
                            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (cheap/fast)',
                        ])
                        ->placeholder('Use task default'),
                ]),

                Forms\Components\Textarea::make('system_prompt')
                    ->label('System prompt')
                    ->helperText('Describes tone, goal, and constraints to the model. Keep terse — every word costs tokens.')
                    ->required()
                    ->rows(8)
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('compliance_rules')
                    ->label('Per-template compliance rules (optional)')
                    ->helperText('Extra rules the compliance agent must apply for this template, on top of the global rules in config/outreach_compliance.php.')
                    ->rows(3)
                    ->columnSpanFull(),
            ]),

            Forms\Components\Section::make('State')->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Inactive templates cannot be drafted from.'),

                    Forms\Components\Toggle::make('autonomous_enabled')
                        ->label('Autonomous send enabled')
                        ->default(false)
                        ->helperText(new HtmlString(
                            '<strong>WARNING:</strong> when enabled, the system will draft, run compliance, ' .
                            'and send messages for this trigger event WITHOUT human review. Cost ceilings + ' .
                            'kill switch still apply. Leave OFF unless you have explicitly designed this template ' .
                            'for autonomous operation.'
                        )),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('trigger_event')
                    ->badge()
                    ->placeholder('manual'),

                Tables\Columns\TextColumn::make('channel')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'WHATSAPP' ? 'success' : 'info'),

                Tables\Columns\IconColumn::make('autonomous_enabled')
                    ->label('Auto')
                    ->boolean()
                    ->trueIcon('heroicon-o-bolt')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-pause-circle')
                    ->falseColor('gray'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('drafts_count')
                    ->label('Drafts')
                    ->counts('drafts')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->options(['WHATSAPP' => 'WhatsApp', 'EMAIL' => 'Email']),
                Tables\Filters\TernaryFilter::make('autonomous_enabled')
                    ->label('Autonomous'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\Action::make('test_draft')
                    ->label('Test draft')
                    ->icon('heroicon-o-beaker')
                    ->color('info')
                    ->form([
                        Forms\Components\Select::make('person_id')
                            ->label('Test recipient')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array =>
                                Person::where('email', 'ilike', "%{$search}%")
                                    ->orWhere('first_name', 'ilike', "%{$search}%")
                                    ->orWhere('last_name', 'ilike', "%{$search}%")
                                    ->limit(20)
                                    ->get(['id', 'first_name', 'last_name', 'email'])
                                    ->mapWithKeys(fn ($p) => [$p->id => trim("{$p->first_name} {$p->last_name}") . " — {$p->email}"])
                                    ->toArray()
                            )
                            ->getOptionLabelUsing(fn ($value): ?string =>
                                Person::find($value)?->email
                            ),
                    ])
                    ->action(function (OutreachTemplate $record, array $data): void {
                        $person = Person::find($data['person_id']);
                        if (! $person) {
                            Notification::make()->title('Person not found')->danger()->send();
                            return;
                        }
                        try {
                            $orch  = app(OutreachOrchestrator::class);
                            $draft = $orch->reviewedDraft($person, $record, auth()->user());

                            $compliance = $draft->complianceCheck;
                            Notification::make()
                                ->title('Test draft created')
                                ->body("Draft ID {$draft->id}; compliance " .
                                    ($compliance?->passed ? 'passed' : 'BLOCKED') .
                                    '. Open the AI Drafts queue to review.')
                                ->success()
                                ->send();
                        } catch (AiOrchestratorException $e) {
                            Notification::make()
                                ->title('AI calls paused')
                                ->body($e->getMessage())
                                ->warning()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Test draft failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOutreachTemplates::route('/'),
            'create' => Pages\CreateOutreachTemplate::route('/create'),
            'edit'   => Pages\EditOutreachTemplate::route('/{record}/edit'),
        ];
    }
}
