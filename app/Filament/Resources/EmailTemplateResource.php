<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\EmailTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EmailTemplateResource extends Resource
{
    protected static ?string $model           = EmailTemplate::class;
    protected static ?string $navigationIcon  = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Email';
    protected static ?string $navigationLabel = 'Templates';
    protected static ?int    $navigationSort  = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Template Details')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Internal Name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. Re-engagement — dormant clients'),

                    Forms\Components\TextInput::make('subject')
                        ->label('Email Subject')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. {{first_name}}, we miss you at Market Funded')
                        ->helperText('Merge tags: {{first_name}}, {{last_name}}, {{full_name}}, {{email}}'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                ]),

            Forms\Components\Section::make('Email Body')
                ->schema([
                    Forms\Components\RichEditor::make('body_html')
                        ->label('Body')
                        ->required()
                        ->toolbarButtons([
                            'bold', 'italic', 'underline', 'strike',
                            'h2', 'h3',
                            'bulletList', 'orderedList',
                            'link', 'blockquote',
                            'undo', 'redo',
                        ])
                        ->helperText(
                            'Available merge tags: {{first_name}}, {{last_name}}, {{full_name}}, {{email}}, {{unsubscribe_url}}. ' .
                            'The unsubscribe link is automatically added to the footer. ' .
                            'A tracking pixel is automatically injected before </body>.'
                        )
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Template Name')
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('subject')
                    ->label('Subject')
                    ->limit(60)
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('campaigns_count')
                    ->label('Used In')
                    ->counts('campaigns')
                    ->suffix(' campaigns'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->date('d M Y')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (EmailTemplate $record) {
                        abort_if($record->campaigns()->exists(), 403, 'Cannot delete a template that has been used in campaigns.');
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => \App\Filament\Resources\EmailTemplateResource\Pages\ListEmailTemplates::route('/'),
            'create' => \App\Filament\Resources\EmailTemplateResource\Pages\CreateEmailTemplate::route('/create'),
            'edit'   => \App\Filament\Resources\EmailTemplateResource\Pages\EditEmailTemplate::route('/{record}/edit'),
        ];
    }
}
