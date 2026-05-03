<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\Branch;
use App\Models\PermissionAuditLog;
use App\Models\PermissionTemplate;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class UserResource extends Resource
{
    protected static ?string $model           = User::class;
    protected static ?string $navigationIcon  = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Users & Permissions';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?int    $navigationSort  = 90;

    // ── Form ─────────────────────────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form->schema([

            // ── Basic info ───────────────────────────────────────────────────
            Forms\Components\Section::make('Account')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(User::class, 'email', ignoreRecord: true),

                    Forms\Components\Select::make('role')
                        ->options([
                            'ADMIN'         => 'Admin',
                            'SALES_MANAGER' => 'Sales Manager',
                            'SALES_AGENT'   => 'Sales Agent',
                            'VIEWER'        => 'Viewer',
                        ])
                        ->required()
                        ->default('SALES_AGENT'),

                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->maxLength(255)
                        ->required(fn ($record) => $record === null) // required on create only
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                        ->dehydrated(fn ($state) => filled($state))
                        ->label(fn ($record) => $record ? 'New password (leave blank to keep current)' : 'Password'),
                ]),

            // ── Template picker ──────────────────────────────────────────────
            Forms\Components\Section::make('Permission Template')
                ->description('Stamps current values onto the toggles below. Does not stay linked — editing the template later will not affect this user.')
                ->schema([
                    Forms\Components\Select::make('_template_id')
                        ->label('Apply a template')
                        ->placeholder('Choose a template to pre-fill permissions…')
                        ->options(fn () => static::templateOptions())
                        ->live()
                        ->afterStateUpdated(function (?string $state, Forms\Set $set): void {
                            if (! $state) {
                                return;
                            }
                            $template = PermissionTemplate::find($state);
                            if (! $template) {
                                return;
                            }
                            // Apply safe toggles (strips is_super_admin for non-super-admin actors)
                            $isSuperAdmin = auth()->user()?->is_super_admin;
                            foreach ($template->toggles ?? [] as $field => $value) {
                                if ($field === 'is_super_admin' && ! $isSuperAdmin) {
                                    continue;
                                }
                                $set($field, $value);
                            }
                        })
                        ->dehydrated(false),

                    // Hidden field carries the applied template ID through to afterCreate/afterSave
                    // so a TEMPLATE_APPLIED audit log entry can be written.
                    Forms\Components\Hidden::make('_applied_template_id')
                        ->afterStateHydrated(fn (Forms\Set $set) => $set('_applied_template_id', null)),
                ]),

            // ── Visibility toggles ───────────────────────────────────────────
            Forms\Components\Section::make('Visibility')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('assigned_only')
                        ->label('Assigned clients only')
                        ->helperText('If on, user only sees clients where they are the account manager.'),

                    Forms\Components\Toggle::make('can_view_client_financials')
                        ->label('View client financials')
                        ->helperText('Per-client deposit/withdrawal history on person detail page.'),

                    Forms\Components\Toggle::make('can_view_branch_financials')
                        ->label('View branch financials')
                        ->helperText('Aggregated branch dashboards, revenue widgets, financial exports.'),

                    Forms\Components\Toggle::make('can_view_health_scores')
                        ->label('View health scores')
                        ->helperText('Health scores on client records and At-Risk Clients widget.'),
                ]),

            // ── Communication toggles ────────────────────────────────────────
            Forms\Components\Section::make('Communication')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('can_make_notes')
                        ->label('Make notes')
                        ->helperText('Create notes on clients. Edit/delete is admin-only.'),

                    Forms\Components\Toggle::make('can_send_whatsapp')
                        ->label('Send WhatsApp')
                        ->helperText('Subject to WA_FEATURE_ENABLED being active.'),

                    Forms\Components\Toggle::make('can_send_email')
                        ->label('Send individual email'),

                    Forms\Components\Toggle::make('can_create_email_campaigns')
                        ->label('Create email campaigns'),
                ]),

            // ── Client management toggles ────────────────────────────────────
            Forms\Components\Section::make('Client Management')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('can_edit_clients')
                        ->label('Edit client records')
                        ->helperText('Full edit scope on client fields.'),

                    Forms\Components\Toggle::make('can_assign_clients')
                        ->label('Assign/reassign clients')
                        ->helperText('Implicitly grants edit on lead_status and account_manager only.'),
                ]),

            // ── Task toggles ─────────────────────────────────────────────────
            Forms\Components\Section::make('Tasks')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('can_create_tasks')
                        ->label('Create tasks'),

                    Forms\Components\Toggle::make('can_assign_tasks_to_others')
                        ->label('Assign tasks to others')
                        ->helperText('Requires can_create_tasks to be meaningful.'),
                ]),

            // ── Data toggles ─────────────────────────────────────────────────
            Forms\Components\Section::make('Data')
                ->schema([
                    Forms\Components\Toggle::make('can_export')
                        ->label('Bulk export to CSV'),
                ]),

            // ── Branch access ────────────────────────────────────────────────
            Forms\Components\Section::make('Branch Access')
                ->description('User can only see clients belonging to their assigned branches (unless super admin).')
                ->schema([
                    Forms\Components\CheckboxList::make('branch_ids')
                        ->label('Accessible branches')
                        ->options(fn () => Branch::where('is_included', true)->orderBy('name')->pluck('name', 'id'))
                        ->columns(2),
                ]),
        ]);
    }

    // ── Table ────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'ADMIN'         => 'danger',
                        'SALES_MANAGER' => 'warning',
                        'SALES_AGENT'   => 'info',
                        'VIEWER'        => 'gray',
                        default         => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_super_admin')
                    ->label('Super Admin')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('branches_count')
                    ->label('Branches')
                    ->counts('branches')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'ADMIN'         => 'Admin',
                        'SALES_MANAGER' => 'Sales Manager',
                        'SALES_AGENT'   => 'Sales Agent',
                        'VIEWER'        => 'Viewer',
                    ]),
                Tables\Filters\TernaryFilter::make('is_super_admin')->label('Super Admin'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('grant_super_admin')
                    ->label('Promote to Super Admin')
                    ->icon('heroicon-o-shield-check')
                    ->color('warning')
                    ->visible(fn (User $record) => auth()->user()?->is_super_admin && ! $record->is_super_admin)
                    ->requiresConfirmation()
                    ->modalHeading('Promote to Super Admin?')
                    ->modalDescription('This user will bypass all permission checks. Only super admins can grant this.')
                    ->action(fn (User $record) => $record->update(['is_super_admin' => true])),
                    // Observer writes SUPER_ADMIN_GRANTED audit log automatically.

                Tables\Actions\Action::make('revoke_super_admin')
                    ->label('Revoke Super Admin')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->visible(fn (User $record) => auth()->user()?->is_super_admin
                        && $record->is_super_admin
                        && $record->id !== auth()->id()) // cannot self-revoke
                    ->requiresConfirmation()
                    ->modalHeading('Revoke Super Admin?')
                    ->action(fn (User $record) => $record->update(['is_super_admin' => false])),
                    // Observer writes SUPER_ADMIN_REVOKED audit log automatically.
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->visible(fn () => auth()->user()?->is_super_admin),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PermissionAuditLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    // ── Shared helpers (used by Create + Edit pages) ─────────────────────────

    /**
     * Template options filtered by actor's super-admin status.
     * Non-super-admins see all templates except "Super Admin".
     */
    public static function templateOptions(): array
    {
        $query = PermissionTemplate::orderBy('display_order');

        if (! auth()->user()?->is_super_admin) {
            $query->where('name', '!=', 'Super Admin');
        }

        return $query->pluck('name', 'id')->toArray();
    }

    /**
     * Syncs the user_branch_access pivot and writes audit log entries for any
     * branches granted or revoked. Called by both Create and Edit pages.
     */
    public static function syncBranchAccess(User $user, array $newBranchIds, ?string $actorId): void
    {
        $oldBranchIds = DB::table('user_branch_access')
            ->where('user_id', $user->id)
            ->pluck('branch_id')
            ->toArray();

        $now = now();

        foreach (array_diff($newBranchIds, $oldBranchIds) as $branchId) {
            DB::table('user_branch_access')->insertOrIgnore([
                'user_id'    => $user->id,
                'branch_id'  => $branchId,
                'granted_at' => $now,
                'granted_by' => $actorId,
            ]);
            PermissionAuditLog::record(
                targetUserId: $user->id,
                changeType: PermissionAuditLog::TYPE_BRANCH_GRANTED,
                changes: ['branch_id' => $branchId],
                actorUserId: $actorId,
            );
        }

        foreach (array_diff($oldBranchIds, $newBranchIds) as $branchId) {
            DB::table('user_branch_access')
                ->where('user_id', $user->id)
                ->where('branch_id', $branchId)
                ->delete();
            PermissionAuditLog::record(
                targetUserId: $user->id,
                changeType: PermissionAuditLog::TYPE_BRANCH_REVOKED,
                changes: ['branch_id' => $branchId],
                actorUserId: $actorId,
            );
        }
    }

    /**
     * Writes a TEMPLATE_APPLIED audit log entry if a template was applied
     * during this save. Called by both Create and Edit pages.
     */
    public static function logTemplateApplication(User $user, ?string $templateId, ?string $actorId): void
    {
        if (! $templateId) {
            return;
        }
        $template = PermissionTemplate::find($templateId);
        if (! $template) {
            return;
        }
        PermissionAuditLog::record(
            targetUserId: $user->id,
            changeType: PermissionAuditLog::TYPE_TEMPLATE_APPLIED,
            changes: [
                'template_id'   => $template->id,
                'template_name' => $template->name,
            ],
            actorUserId: $actorId,
        );
    }
}
