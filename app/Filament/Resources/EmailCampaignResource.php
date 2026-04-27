<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\EmailCampaign;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EmailCampaignResource extends Resource
{
    protected static ?string $model           = EmailCampaign::class;
    protected static ?string $navigationIcon  = 'heroicon-o-envelope';
    protected static ?string $navigationGroup = 'Email';
    protected static ?string $navigationLabel = 'Campaigns';
    protected static ?int    $navigationSort  = 21;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Campaign Details')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Campaign Name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. April Re-engagement — At-Risk Clients'),

                    Forms\Components\Select::make('email_template_id')
                        ->label('Template')
                        ->options(fn () => \App\Models\EmailTemplate::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if ($state) {
                                $template = \App\Models\EmailTemplate::find($state);
                                if ($template) {
                                    $set('subject_override', $template->subject);
                                }
                            }
                        }),

                    Forms\Components\TextInput::make('subject_override')
                        ->label('Subject Line')
                        ->maxLength(255)
                        ->helperText('Leave blank to use the template subject. Supports merge tags.'),

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('from_name')
                            ->label('From Name')
                            ->default('Market Funded')
                            ->required(),

                        Forms\Components\TextInput::make('from_email')
                            ->label('From Email')
                            ->email()
                            ->default('info@market-funded.com')
                            ->required(),
                    ]),
                ]),

            Forms\Components\Section::make('Recipients')
                ->schema([
                    Forms\Components\Select::make('recipient_mode')
                        ->label('Recipient Mode')
                        ->options([
                            'FILTER'   => 'Saved Filter (segment)',
                            'MANUAL'   => 'Manual Selection',
                            'COMBINED' => 'Filter + Manual',
                        ])
                        ->default('FILTER')
                        ->required()
                        ->live(),

                    Forms\Components\Select::make('recipient_filter_key')
                        ->label('Recipient Filter')
                        ->options(\App\Jobs\Email\BuildCampaignRecipientsJob::filterOptions())
                        ->searchable()
                        ->visible(fn (Forms\Get $get) => in_array($get('recipient_mode'), ['FILTER', 'COMBINED']))
                        ->required(fn (Forms\Get $get) => $get('recipient_mode') === 'FILTER'),

                    Forms\Components\Select::make('recipient_manual_ids')
                        ->label('Manual Recipients')
                        ->multiple()
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
                        ->visible(fn (Forms\Get $get) => in_array($get('recipient_mode'), ['MANUAL', 'COMBINED'])),
                ]),

            Forms\Components\Section::make('Scheduling')
                ->schema([
                    Forms\Components\DateTimePicker::make('scheduled_at')
                        ->label('Schedule Send')
                        ->native(false)
                        ->minDate(now())
                        ->helperText('Leave blank to send immediately when approved.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Campaign')
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray'    => 'DRAFT',
                        'warning' => 'SCHEDULED',
                        'info'    => 'SENDING',
                        'success' => 'SENT',
                        'danger'  => fn ($state) => in_array($state, ['CANCELLED', 'FAILED']),
                    ]),

                Tables\Columns\TextColumn::make('template.name')
                    ->label('Template')
                    ->limit(30),

                Tables\Columns\TextColumn::make('recipient_count')
                    ->label('Recipients')
                    ->numeric()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('sent_count')
                    ->label('Sent')
                    ->numeric()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('open_rate')
                    ->label('Open Rate')
                    ->formatStateUsing(fn ($state) => $state . '%')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('click_rate')
                    ->label('Click Rate')
                    ->formatStateUsing(fn ($state) => $state . '%')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('scheduled_at')
                    ->label('Scheduled')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Immediate')
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('send')
                    ->label('Send Now')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn ($record) => $record->isDraft())
                    ->requiresConfirmation()
                    ->modalHeading('Send Campaign')
                    ->modalDescription(fn ($record) => "This will send \"{$record->name}\" to your selected recipients. This cannot be undone.")
                    ->action(function ($record) {
                        dispatch(new \App\Jobs\Email\BuildCampaignRecipientsJob($record->id));
                        \Filament\Notifications\Notification::make()
                            ->title('Campaign queued')
                            ->body('Recipients are being built. Sending will start shortly.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('testSend')
                    ->label('Test Send')
                    ->icon('heroicon-o-beaker')
                    ->color('info')
                    ->visible(fn ($record) => $record->isDraft())
                    ->form([
                        Forms\Components\TextInput::make('test_email')
                            ->label('Send test to')
                            ->email()
                            ->default(auth()->user()?->email)
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $template = $record->template;
                        $person   = auth()->user()
                            ? \App\Models\Person::where('email', auth()->user()->email)->first()
                            : null;

                        if (! $person) {
                            $person = new \App\Models\Person([
                                'first_name' => 'Test',
                                'last_name'  => 'User',
                                'email'      => $data['test_email'],
                            ]);
                        }

                        ['subject' => $subject, 'html' => $html] = $template->render(
                            $person,
                            unsubscribeUrl: '#unsubscribe-test',
                            trackingPixelUrl: '#tracking-test',
                        );

                        \Illuminate\Support\Facades\Mail::send([], [], function ($m) use ($data, $subject, $html, $record) {
                            $m->to($data['test_email'])
                              ->from($record->from_email, $record->from_name)
                              ->subject('[TEST] ' . $subject)
                              ->html($html);
                        });

                        \Filament\Notifications\Notification::make()
                            ->title('Test email sent')
                            ->body("Sent to {$data['test_email']}")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn ($record) => $record->isDraft()),

                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => \App\Filament\Resources\EmailCampaignResource\Pages\ListEmailCampaigns::route('/'),
            'create' => \App\Filament\Resources\EmailCampaignResource\Pages\CreateEmailCampaign::route('/create'),
            'view'   => \App\Filament\Resources\EmailCampaignResource\Pages\ViewEmailCampaign::route('/{record}'),
            'edit'   => \App\Filament\Resources\EmailCampaignResource\Pages\EditEmailCampaign::route('/{record}/edit'),
        ];
    }
}
