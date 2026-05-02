<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\PermissionAuditLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PermissionAuditLogRelationManager extends RelationManager
{
    protected static string $relationship = 'permissionAuditLogs';

    protected static ?string $title = 'Permission History';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('change_type')
                    ->label('Change')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        PermissionAuditLog::TYPE_SUPER_ADMIN_GRANTED => 'danger',
                        PermissionAuditLog::TYPE_SUPER_ADMIN_REVOKED => 'warning',
                        PermissionAuditLog::TYPE_BRANCH_GRANTED      => 'success',
                        PermissionAuditLog::TYPE_BRANCH_REVOKED      => 'danger',
                        PermissionAuditLog::TYPE_TEMPLATE_APPLIED    => 'info',
                        default                                       => 'gray',
                    }),

                Tables\Columns\TextColumn::make('changes')
                    ->label('Detail')
                    ->formatStateUsing(function (mixed $state): string {
                        $data = is_string($state) ? (json_decode($state, true) ?? []) : (array) $state;
                        if (isset($data['field'])) {
                            $from = $data['from'] ? 'true' : 'false';
                            $to   = $data['to']   ? 'true' : 'false';
                            return "{$data['field']}: {$from} → {$to}";
                        }
                        if (isset($data['template_name'])) {
                            return "Template: {$data['template_name']}";
                        }
                        if (isset($data['branch_id'])) {
                            return "Branch ID: {$data['branch_id']}";
                        }
                        return json_encode($data);
                    }),

                Tables\Columns\TextColumn::make('actor.name')
                    ->label('Changed by')
                    ->default('System')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->paginated([10, 25, 50]);
    }
}
