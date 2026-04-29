<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AgentResource\Pages;
use App\Models\Agent;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AgentResource extends Resource
{
    protected static ?string $model           = Agent::class;
    protected static ?string $navigationIcon  = 'heroicon-o-cpu-chip';
    protected static ?string $navigationLabel = 'Agents';
    protected static ?string $navigationGroup = 'WhatsApp';
    protected static ?int    $navigationSort  = 22;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('key')
                        ->label('Key (slug)')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn ($record) => $record !== null), // key is immutable after creation

                    Forms\Components\TextInput::make('name')
                        ->label('Display name')
                        ->required()
                        ->maxLength(100),

                    Forms\Components\Select::make('department')
                        ->options([
                            'EDUCATION'  => 'Education',
                            'DEPOSITS'   => 'Deposits',
                            'CHALLENGES' => 'Challenges',
                            'SUPPORT'    => 'Support',
                            'ONBOARDING' => 'Onboarding',
                            'RETENTION'  => 'Retention',
                            'NURTURING'  => 'Nurturing',
                            'GENERAL'    => 'General',
                        ])
                        ->required(),
                ]),

                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),

                Forms\Components\Textarea::make('system_prompt')
                    ->label('System prompt (fill in when AI integration is ready)')
                    ->rows(10)
                    ->placeholder('Leave blank for now — Werner will fill these in when AI routing is configured.')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('department')
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('Key')
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('name')
                    ->searchable(),

                Tables\Columns\TextColumn::make('department')
                    ->badge(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('system_prompt')
                    ->label('Has prompt')
                    ->formatStateUsing(fn (?string $state) => $state ? '✓ Set' : '— Empty')
                    ->color(fn (?string $state) => $state ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgents::route('/'),
            'edit'  => Pages\EditAgent::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // Agents are seeded — Werner only edits system prompts and toggles active state
    }
}
