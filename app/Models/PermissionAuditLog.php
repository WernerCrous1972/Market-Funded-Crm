<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit trail for all permission changes.
 * No updated_at — records are never modified after creation.
 */
class PermissionAuditLog extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null; // immutable — no updated_at column

    public const TYPE_TOGGLE_CHANGED      = 'TOGGLE_CHANGED';
    public const TYPE_BRANCH_GRANTED      = 'BRANCH_GRANTED';
    public const TYPE_BRANCH_REVOKED      = 'BRANCH_REVOKED';
    public const TYPE_TEMPLATE_APPLIED    = 'TEMPLATE_APPLIED';
    public const TYPE_SUPER_ADMIN_GRANTED = 'SUPER_ADMIN_GRANTED';
    public const TYPE_SUPER_ADMIN_REVOKED = 'SUPER_ADMIN_REVOKED';

    protected $fillable = [
        'target_user_id',
        'actor_user_id',
        'change_type',
        'changes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    // ── Factory helper ───────────────────────────────────────────────────────

    public static function record(
        string $targetUserId,
        string $changeType,
        array $changes,
        ?string $actorUserId = null,
    ): self {
        return self::create([
            'target_user_id' => $targetUserId,
            'actor_user_id'  => $actorUserId ?? auth()->id(),
            'change_type'    => $changeType,
            'changes'        => $changes,
            'created_at'     => now(),
        ]);
    }
}
