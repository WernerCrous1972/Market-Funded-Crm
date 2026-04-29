<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\WhatsAppMessageResource\Pages;
use App\Models\WhatsAppMessage;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WhatsAppMessageResource extends Resource
{
    protected static ?string $model           = WhatsAppMessage::class;
    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'WA Messages';
    protected static ?string $navigationGroup = 'WhatsApp';
    protected static ?int    $navigationSort  = 21;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('person.full_name')
                    ->label('Person')
                    ->searchable(['people.first_name', 'people.last_name'])
                    ->url(fn (WhatsAppMessage $r) => $r->person_id
                        ? \App\Filament\Resources\PersonResource::getUrl('view', ['record' => $r->person_id])
                        : null),

                Tables\Columns\BadgeColumn::make('direction')
                    ->colors([
                        'success' => 'OUTBOUND',
                        'info'    => 'INBOUND',
                    ]),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray'    => 'PENDING',
                        'success' => fn ($state) => in_array($state, ['SENT', 'DELIVERED', 'READ', 'RECEIVED']),
                        'danger'  => 'FAILED',
                    ]),

                Tables\Columns\TextColumn::make('body_text')
                    ->label('Message')
                    ->limit(60)
                    ->tooltip(fn (WhatsAppMessage $r) => $r->body_text),

                Tables\Columns\TextColumn::make('template.name')
                    ->label('Template')
                    ->placeholder('Free-form')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('agent_key')
                    ->label('Agent')
                    ->placeholder('Manual')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sentByUser.name')
                    ->label('Sent by')
                    ->placeholder('Autonomous')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('direction')
                    ->options(['OUTBOUND' => 'Outbound', 'INBOUND' => 'Inbound']),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'PENDING'   => 'Pending',
                        'SENT'      => 'Sent',
                        'DELIVERED' => 'Delivered',
                        'READ'      => 'Read',
                        'FAILED'    => 'Failed',
                        'RECEIVED'  => 'Received',
                    ]),

                Tables\Filters\SelectFilter::make('agent_key')
                    ->label('Agent')
                    ->options(fn () => \App\Models\Agent::pluck('name', 'key')->toArray()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWhatsAppMessages::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
