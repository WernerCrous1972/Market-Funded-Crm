<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reusable AI prompt template for outbound messages.
 *
 * One template per (trigger_event, channel). Admin enables `autonomous_enabled`
 * before any event-driven send fires; new templates always start disabled.
 */
class OutreachTemplate extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'trigger_event',
        'channel',
        'system_prompt',
        'compliance_rules',
        'model_preference',
        'autonomous_enabled',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'autonomous_enabled' => 'boolean',
        'is_active'          => 'boolean',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(AiDraft::class, 'template_id');
    }
}
