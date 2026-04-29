<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\WhatsAppTemplateResource\Pages;
use App\Models\WhatsAppTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WhatsAppTemplateResource extends Resource
{
    protected static ?string $model           = WhatsAppTemplate::class;
    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-bottom-center-text';
    protected static ?string $navigationLabel = 'WA Templates';
    protected static ?string $navigationGroup = 'WhatsApp';
    protected static ?int    $navigationSort  = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Template name (must match Meta exactly)')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->columnSpanFull(),

                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\Select::make('category')
                        ->options([
                            'MARKETING'      => 'Marketing',
                            'UTILITY'        => 'Utility',
                            'AUTHENTICATION' => 'Authentication',
                            'SERVICE'        => 'Service',
                        ])
                        ->required(),

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

                    Forms\Components\TextInput::make('language_code')
                        ->label('Language code')
                        ->default('en')
                        ->required()
                        ->maxLength(10),
                ]),

                Forms\Components\Textarea::make('body_text')
                    ->label('Body text (use {{1}}, {{2}} for variables)')
                    ->required()
                    ->rows(6)
                    ->columnSpanFull(),

                Forms\Components\KeyValue::make('variables')
                    ->label('Variables (name → description)')
                    ->keyLabel('Variable name')
                    ->valueLabel('Description / example')
                    ->columnSpanFull(),

                Forms\Components\Section::make('Meta status')->schema([
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                'DRAFT'            => 'Draft',
                                'PENDING_APPROVAL' => 'Submitted to Meta (pending)',
                                'APPROVED'         => 'Approved',
                                'REJECTED'         => 'Rejected',
                                'PAUSED'           => 'Paused',
                            ])
                            ->required(),

                        Forms\Components\TextInput::make('meta_template_id')
                            ->label('Meta template ID')
                            ->maxLength(100),

                        Forms\Components\DateTimePicker::make('approved_at')
                            ->label('Approved at')
                            ->timezone('Africa/Johannesburg'),
                    ]),
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

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray'    => 'DRAFT',
                        'warning' => 'PENDING_APPROVAL',
                        'success' => 'APPROVED',
                        'danger'  => 'REJECTED',
                        'info'    => 'PAUSED',
                    ]),

                Tables\Columns\TextColumn::make('category')
                    ->badge(),

                Tables\Columns\TextColumn::make('department')
                    ->badge(),

                Tables\Columns\TextColumn::make('language_code')
                    ->label('Lang'),

                Tables\Columns\TextColumn::make('approved_at')
                    ->label('Approved')
                    ->date('d M Y')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'DRAFT'            => 'Draft',
                        'PENDING_APPROVAL' => 'Pending',
                        'APPROVED'         => 'Approved',
                        'REJECTED'         => 'Rejected',
                        'PAUSED'           => 'Paused',
                    ]),
                Tables\Filters\SelectFilter::make('department')
                    ->options([
                        'EDUCATION' => 'Education', 'DEPOSITS' => 'Deposits',
                        'CHALLENGES' => 'Challenges', 'SUPPORT' => 'Support',
                        'ONBOARDING' => 'Onboarding', 'RETENTION' => 'Retention',
                        'NURTURING' => 'Nurturing', 'GENERAL' => 'General',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWhatsAppTemplates::route('/'),
            'create' => Pages\CreateWhatsAppTemplate::route('/create'),
            'edit'   => Pages\EditWhatsAppTemplate::route('/{record}/edit'),
        ];
    }
}
