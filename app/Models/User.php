<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
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
}
