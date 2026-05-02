<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        // ── Phase B permission toggles ──────────────────────────────────────
        'is_super_admin',
        'assigned_only',
        'can_view_client_financials',
        'can_view_branch_financials',
        'can_view_health_scores',
        'can_make_notes',
        'can_send_whatsapp',
        'can_send_email',
        'can_create_email_campaigns',
        'can_edit_clients',
        'can_assign_clients',
        'can_create_tasks',
        'can_assign_tasks_to_others',
        'can_export',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'          => 'datetime',
            'password'                   => 'hashed',
            // ── Phase B booleans ─────────────────────────────────────────────
            'is_super_admin'             => 'boolean',
            'assigned_only'              => 'boolean',
            'can_view_client_financials' => 'boolean',
            'can_view_branch_financials' => 'boolean',
            'can_view_health_scores'     => 'boolean',
            'can_make_notes'             => 'boolean',
            'can_send_whatsapp'          => 'boolean',
            'can_send_email'             => 'boolean',
            'can_create_email_campaigns' => 'boolean',
            'can_edit_clients'           => 'boolean',
            'can_assign_clients'         => 'boolean',
            'can_create_tasks'           => 'boolean',
            'can_assign_tasks_to_others' => 'boolean',
            'can_export'                 => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branch_access')
            ->withPivot(['granted_at', 'granted_by'])
            ->withTimestamps(createdAt: 'granted_at', updatedAt: null);
    }

    public function permissionAuditLogs(): HasMany
    {
        return $this->hasMany(PermissionAuditLog::class, 'target_user_id')
            ->orderByDesc('created_at');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, ['ADMIN', 'SALES_MANAGER', 'SALES_AGENT', 'VIEWER'], true);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'ADMIN';
    }

    public function isSalesManager(): bool
    {
        return in_array($this->role, ['ADMIN', 'SALES_MANAGER'], true);
    }

    /** Returns true if this user has access to the given branch ID. */
    public function hasBranchAccess(string $branchId): bool
    {
        return $this->is_super_admin
            || $this->branches()->where('branches.id', $branchId)->exists();
    }
}
