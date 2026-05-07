<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OutreachInboundMessageResource\Pages;
use App\Models\OutreachInboundMessage;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only view of inbound replies the AI classifier processed.
 *
 * One row per inbound the listener routed (auto-replied or escalated).
 * Surfaces intent, confidence, and the routing decision so Werner can tune
 * the confidence threshold from real data.
 *
 * Non-admins see only their own owned-clients' inbound messages.
 */
class OutreachInboundMessageResource extends Resource
{
    protected static ?string $model           = OutreachInboundMessage::class;
    protected static ?string $navigationIcon  = 'heroicon-o-inbox-arrow-down';
    protected static ?string $navigationLabel = 'Inbound Messages';
    protected static ?string $navigationGroup = 'AI Outreach';
    protected static ?int    $navigationSort  = 30;

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        return $user?->is_super_admin || $user?->can_edit_clients;
    }

    public static function canCreate(): bool
    {
        return false; // Read-only — rows created by the inbound listener.
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['person', 'autoReplyDraft', 'assignedToUser', 'whatsappMessage']);

        $user = auth()->user();
        if (! $user?->is_super_admin) {
            $query->whereHas('person', fn ($p) => $p->where('account_manager_user_id', $user?->id));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('person.full_name')
                    ->label('From')
                    ->getStateUsing(fn (OutreachInboundMessage $r): string =>
                        trim(($r->person?->first_name ?? '') . ' ' . ($r->person?->last_name ?? '')) ?: ($r->person?->email ?? '(unknown)'))
                    ->searchable(['first_name', 'last_name', 'email']),

                Tables\Columns\TextColumn::make('intent')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'acknowledgment'    => 'success',
                        'simple_question'   => 'success',
                        'complex_question'  => 'warning',
                        'complaint'         => 'danger',
                        'sensitive_request' => 'danger',
                        'unsubscribe'       => 'danger',
                        'unclear'           => 'gray',
                        default             => 'gray',
                    }),

                Tables\Columns\TextColumn::make('confidence')
                    ->label('Conf.')
                    ->suffix('%')
                    ->color(fn (?int $state): string => match (true) {
                        $state === null      => 'gray',
                        $state >= 75         => 'success',
                        $state >= 50         => 'warning',
                        default              => 'danger',
                    }),

                Tables\Columns\TextColumn::make('routing')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        OutreachInboundMessage::ROUTING_AUTO_REPLIED        => 'success',
                        OutreachInboundMessage::ROUTING_ESCALATED_TO_AGENT  => 'warning',
                        OutreachInboundMessage::ROUTING_ESCALATED_TO_HENRY  => 'danger',
                        default                                              => 'gray',
                    }),

                Tables\Columns\TextColumn::make('assignedToUser.name')
                    ->label('Assigned to')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('whatsappMessage.body_text')
                    ->label('Message')
                    ->limit(60)
                    ->tooltip(fn (OutreachInboundMessage $r): ?string => $r->whatsappMessage?->body_text)
                    ->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('routing')
                    ->options([
                        OutreachInboundMessage::ROUTING_AUTO_REPLIED       => 'Auto-replied',
                        OutreachInboundMessage::ROUTING_ESCALATED_TO_AGENT => 'Escalated to agent',
                        OutreachInboundMessage::ROUTING_ESCALATED_TO_HENRY => 'Escalated to Henry',
                    ]),
                Tables\Filters\SelectFilter::make('intent')
                    ->options(fn () => collect((array) config('outreach_inbound.intents', []))
                        ->mapWithKeys(fn ($i) => [$i => $i])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalHeading('Inbound message'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOutreachInboundMessages::route('/'),
        ];
    }
}
