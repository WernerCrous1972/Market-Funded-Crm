<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AiDraftResource\Pages;
use App\Models\AiDraft;
use App\Services\WhatsApp\MessageSender;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Review queue for AI-generated drafts.
 *
 * Default view is "pending review" — drafts an agent or admin needs to look
 * at, edit if needed, then approve+send or reject. Other statuses (sent,
 * blocked_compliance, failed, rejected) are accessible via the status filter.
 *
 * The "approve & send" action edits the draft inline, sets final_text +
 * status=sent, and dispatches via WhatsApp MessageSender. The actual send is
 * a no-op while WA_FEATURE_ENABLED=false (we just log) — that's by design;
 * the review pipeline can be exercised end-to-end before WhatsApp is live.
 */
class AiDraftResource extends Resource
{
    protected static ?string $model           = AiDraft::class;
    protected static ?string $navigationIcon  = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'AI Drafts';
    protected static ?string $navigationGroup = 'AI Outreach';
    protected static ?int    $navigationSort  = 20;

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        // Admins and anyone with can_edit_clients can review; sales agents
        // can see their own drafts only (filtered in getEloquentQuery).
        return $user?->is_super_admin || $user?->can_edit_clients;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery()->with(['person', 'template', 'complianceCheck']);

        $user = auth()->user();
        if (! $user?->is_super_admin) {
            // Non-admins see only drafts triggered by themselves OR drafts
            // for clients they own (account_manager_user_id = their id).
            $query->where(function ($q) use ($user) {
                $q->where('triggered_by_user_id', $user?->id)
                  ->orWhereHas('person', fn ($p) => $p->where('account_manager_user_id', $user?->id));
            });
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Draft')->schema([
                Forms\Components\Placeholder::make('person')
                    ->content(fn (AiDraft $record): string =>
                        trim(($record->person->first_name ?? '') . ' ' . ($record->person->last_name ?? '')) .
                        ' — ' . ($record->person->email ?? '(no email)')),

                Forms\Components\Placeholder::make('template_name')
                    ->label('Template')
                    ->content(fn (AiDraft $record): string => $record->template->name ?? '(no template)'),

                Forms\Components\Textarea::make('final_text')
                    ->label('Final message (edit before approve)')
                    ->default(fn (AiDraft $record): ?string => $record->final_text ?? $record->draft_text)
                    ->rows(8)
                    ->required()
                    ->helperText('Original AI draft prefilled here. Edit freely; the original is preserved in draft_text.')
                    ->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Compliance')->schema([
                Forms\Components\Placeholder::make('compliance_summary')
                    ->content(function (AiDraft $record): HtmlString {
                        $check = $record->complianceCheck;
                        if (! $check) {
                            return new HtmlString('<em>No compliance check on this draft.</em>');
                        }
                        $verdict = e($check->verdict_text ?? 'No verdict text.');
                        $passColour = $check->passed ? 'green' : 'red';
                        $passLabel  = $check->passed ? 'PASSED' : 'BLOCKED';
                        $flagsHtml  = '';
                        foreach ($check->flags as $f) {
                            $sev = $f['severity'] ?? '?';
                            $colour = $sev === 'hard' ? 'red' : 'amber';
                            $rule    = e($f['rule'] ?? '?');
                            $excerpt = e($f['excerpt'] ?? '');
                            $flagsHtml .= "<li><span style=\"color:{$colour}\"><strong>[{$sev}]</strong></span> <strong>{$rule}</strong>: {$excerpt}</li>";
                        }
                        $flagsBlock = $flagsHtml ? "<ul style=\"margin-top:0.5rem;\">{$flagsHtml}</ul>" : '<em>No flags raised.</em>';
                        return new HtmlString(
                            "<div><strong style=\"color:{$passColour}\">{$passLabel}</strong> &mdash; {$verdict}</div>{$flagsBlock}"
                        );
                    })
                    ->columnSpanFull(),
            ])->collapsible(),

            Forms\Components\Section::make('Provenance (read-only)')->schema([
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\Placeholder::make('mode')
                        ->content(fn (AiDraft $record): string => $record->mode),
                    Forms\Components\Placeholder::make('channel')
                        ->content(fn (AiDraft $record): string => $record->channel),
                    Forms\Components\Placeholder::make('model_used')
                        ->content(fn (AiDraft $record): string => $record->model_used),
                    Forms\Components\Placeholder::make('tokens')
                        ->content(fn (AiDraft $record): string => "{$record->tokens_input} in / {$record->tokens_output} out"),
                    Forms\Components\Placeholder::make('cost_cents')
                        ->content(fn (AiDraft $record): string => "{$record->cost_cents}¢"),
                    Forms\Components\Placeholder::make('triggered_by_event')
                        ->content(fn (AiDraft $record): string => $record->triggered_by_event ?? '(manual)'),
                ]),
            ])->collapsible()->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('person.email')
                    ->label('Recipient')
                    ->searchable()
                    ->formatStateUsing(fn (AiDraft $record): string =>
                        trim(($record->person?->first_name ?? '') . ' ' . ($record->person?->last_name ?? '')) .
                        ' — ' . ($record->person?->email ?? '(no email)')
                    )
                    ->wrap(),

                Tables\Columns\TextColumn::make('template.name')
                    ->label('Template')
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('mode')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'AUTONOMOUS'    => 'warning',
                        'BULK_REVIEWED' => 'info',
                        default         => 'gray',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending_review'      => 'warning',
                        'approved'            => 'info',
                        'sent'                => 'success',
                        'rejected'            => 'gray',
                        'failed'              => 'danger',
                        'blocked_compliance'  => 'danger',
                        default               => 'gray',
                    }),

                Tables\Columns\TextColumn::make('compliance_check_passed')
                    ->label('Compliance')
                    ->state(fn (AiDraft $record): string => match (true) {
                        $record->complianceCheck === null      => '—',
                        $record->complianceCheck->passed       => '✓ pass' . (count($record->complianceCheck->flags) ? ' (' . count($record->complianceCheck->flags) . ' soft)' : ''),
                        default                                 => '✗ blocked',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, '✓') => 'success',
                        str_starts_with($state, '✗') => 'danger',
                        default                       => 'gray',
                    }),

                Tables\Columns\TextColumn::make('cost_cents')
                    ->label('Cost')
                    ->state(fn (AiDraft $record): string => $record->cost_cents . '¢'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending_review'     => 'Pending review',
                        'approved'           => 'Approved',
                        'sent'               => 'Sent',
                        'rejected'           => 'Rejected',
                        'failed'             => 'Failed',
                        'blocked_compliance' => 'Blocked (compliance)',
                    ])
                    ->default('pending_review'),
                Tables\Filters\SelectFilter::make('mode')
                    ->options([
                        'AUTONOMOUS'    => 'Autonomous',
                        'REVIEWED'      => 'Reviewed',
                        'BULK_REVIEWED' => 'Bulk reviewed',
                    ]),
                Tables\Filters\SelectFilter::make('channel')
                    ->options(['WHATSAPP' => 'WhatsApp', 'EMAIL' => 'Email']),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Review')
                    ->icon('heroicon-o-eye'),

                Tables\Actions\Action::make('approve_send')
                    ->label('Approve & send')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (AiDraft $record): bool => $record->status === AiDraft::STATUS_PENDING_REVIEW)
                    ->requiresConfirmation()
                    ->modalHeading('Approve and send this draft?')
                    ->modalDescription('Sends via the WhatsApp pipeline. While WA_FEATURE_ENABLED is false, this is logged but not actually sent to the recipient.')
                    ->action(function (AiDraft $record): void {
                        $record->final_text = $record->final_text ?? $record->draft_text;
                        $record->status     = AiDraft::STATUS_APPROVED;
                        $record->save();

                        try {
                            app(MessageSender::class)->send(
                                person: $record->person,
                                body:   $record->final_text,
                                sentByUser: auth()->user(),
                            );
                            $record->status  = AiDraft::STATUS_SENT;
                            $record->sent_at = now();
                            $record->save();

                            Notification::make()
                                ->title('Sent')
                                ->body('Draft dispatched via WhatsApp pipeline.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            $record->status = AiDraft::STATUS_FAILED;
                            $record->save();
                            Notification::make()
                                ->title('Send failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (AiDraft $record): bool => $record->status === AiDraft::STATUS_PENDING_REVIEW)
                    ->requiresConfirmation()
                    ->action(function (AiDraft $record): void {
                        $record->update(['status' => AiDraft::STATUS_REJECTED]);
                        Notification::make()->title('Rejected')->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('reject_bulk')
                        ->label('Reject selected')
                        ->icon('heroicon-o-x-mark')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            $count = 0;
                            foreach ($records as $r) {
                                if ($r->status === AiDraft::STATUS_PENDING_REVIEW) {
                                    $r->update(['status' => AiDraft::STATUS_REJECTED]);
                                    $count++;
                                }
                            }
                            Notification::make()
                                ->title("Rejected {$count} drafts")
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiDrafts::route('/'),
            'edit'  => Pages\EditAiDraft::route('/{record}/edit'),
        ];
    }
}
