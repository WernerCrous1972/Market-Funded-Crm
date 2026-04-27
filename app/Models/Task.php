<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'person_id',
        'assigned_to_user_id',
        'created_by_user_id',
        'auto_assigned',
        'task_type',
        'title',
        'description',
        'due_at',
        'completed_at',
        'priority',
    ];

    protected $casts = [
        'due_at'        => 'datetime',
        'completed_at'  => 'datetime',
        'auto_assigned' => 'boolean',
    ];

    // ── Task type constants ───────────────────────────────────────────────────

    public const TYPE_GENERAL           = 'GENERAL';
    public const TYPE_FOLLOW_UP         = 'FOLLOW_UP';
    public const TYPE_CALL              = 'CALL';
    public const TYPE_DEPOSIT_FOLLOW_UP = 'DEPOSIT_FOLLOW_UP';
    public const TYPE_WITHDRAWAL_QUERY  = 'WITHDRAWAL_QUERY';
    public const TYPE_KYC               = 'KYC';
    public const TYPE_OTHER             = 'OTHER';

    public const TYPES = [
        self::TYPE_GENERAL           => '📋 General',
        self::TYPE_FOLLOW_UP         => '📞 Follow Up',
        self::TYPE_CALL              => '📱 Call',
        self::TYPE_DEPOSIT_FOLLOW_UP => '💰 Deposit Follow-up',
        self::TYPE_WITHDRAWAL_QUERY  => '💸 Withdrawal Query',
        self::TYPE_KYC               => '🪪 KYC',
        self::TYPE_OTHER             => '📌 Other',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->whereNull('completed_at');
    }

    public function scopeCompleted($query)
    {
        return $query->whereNotNull('completed_at');
    }

    public function scopeOverdue($query)
    {
        return $query->whereNull('completed_at')
                     ->whereNotNull('due_at')
                     ->where('due_at', '<', now());
    }

    public function scopeDueToday($query)
    {
        return $query->whereNull('completed_at')
                     ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]);
    }

    public function scopeDueThisWeek($query)
    {
        return $query->whereNull('completed_at')
                     ->whereBetween('due_at', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeForUser($query, string $userId)
    {
        return $query->where('assigned_to_user_id', $userId);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getIsOverdueAttribute(): bool
    {
        return ! $this->completed_at
            && $this->due_at
            && $this->due_at->isPast();
    }

    public function getIsDueTodayAttribute(): bool
    {
        return ! $this->completed_at
            && $this->due_at
            && $this->due_at->isToday();
    }

    public function getTypeIconAttribute(): string
    {
        return match ($this->task_type) {
            self::TYPE_FOLLOW_UP         => '📞',
            self::TYPE_CALL              => '📱',
            self::TYPE_DEPOSIT_FOLLOW_UP => '💰',
            self::TYPE_WITHDRAWAL_QUERY  => '💸',
            self::TYPE_KYC               => '🪪',
            default                      => '📋',
        };
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Mark this task as complete and log an activity.
     */
    public function markComplete(?string $userId = null): void
    {
        $this->update(['completed_at' => now()]);

        Activity::record(
            personId: $this->person_id,
            type: Activity::TYPE_TASK_COMPLETED,
            description: "Task completed: {$this->title}",
            userId: $userId ?? $this->assigned_to_user_id,
        );
    }

    /**
     * Resolve the correct assignee for a task on a given person.
     *
     * Option C logic:
     * 1. If $explicitUserId is provided → use that (manual override)
     * 2. Else if person has an account_manager that matches a User → auto-assign
     * 3. Else → leave null (unassigned)
     *
     * Returns ['user_id' => string|null, 'auto_assigned' => bool]
     */
    public static function resolveAssignee(Person $person, ?string $explicitUserId = null): array
    {
        if ($explicitUserId) {
            return ['user_id' => $explicitUserId, 'auto_assigned' => false];
        }

        if ($person->account_manager) {
            $user = User::where('name', $person->account_manager)
                ->orWhere('email', $person->account_manager)
                ->first();

            if ($user) {
                return ['user_id' => $user->id, 'auto_assigned' => true];
            }
        }

        return ['user_id' => null, 'auto_assigned' => false];
    }
}
