<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null; // activities only have created_at

    public const TYPES = [
        'DEPOSIT', 'WITHDRAWAL', 'LOGIN', 'TRADE_OPENED',
        'NOTE_ADDED', 'EMAIL_SENT', 'EMAIL_OPENED',
        'CALL_LOG', 'WHATSAPP_SENT', 'TASK_CREATED', 'TASK_COMPLETED',
        'STATUS_CHANGED', 'DUPLICATE_DETECTED',
    ];

    // Named constants for use in code (avoids magic strings)
    public const TYPE_DEPOSIT            = 'DEPOSIT';
    public const TYPE_WITHDRAWAL         = 'WITHDRAWAL';
    public const TYPE_NOTE_ADDED         = 'NOTE_ADDED';
    public const TYPE_TASK_CREATED       = 'TASK_CREATED';
    public const TYPE_TASK_COMPLETED     = 'TASK_COMPLETED';
    public const TYPE_STATUS_CHANGED     = 'STATUS_CHANGED';
    public const TYPE_EMAIL_SENT         = 'EMAIL_SENT';
    public const TYPE_WHATSAPP_SENT      = 'WHATSAPP_SENT';
    public const TYPE_CALL_LOG           = 'CALL_LOG';
    public const TYPE_DUPLICATE_DETECTED = 'DUPLICATE_DETECTED';

    protected $fillable = [
        'person_id',
        'type',
        'description',
        'metadata',
        'user_id',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata'    => 'array',
            'occurred_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeRecent(Builder $query, int $limit = 20): Builder
    {
        return $query->orderByDesc('occurred_at')->limit($limit);
    }

    // ── Factory helper ───────────────────────────────────────────────────────

    public static function record(
        string $personId,
        string $type,
        string $description,
        array $metadata = [],
        ?string $userId = null,
        ?\DateTimeInterface $occurredAt = null,
    ): self {
        return self::create([
            'person_id'   => $personId,
            'type'        => $type,
            'description' => $description,
            'metadata'    => $metadata ?: null,
            'user_id'     => $userId,
            'occurred_at' => $occurredAt ?? now(),
            'created_at'  => now(),
        ]);
    }
}
