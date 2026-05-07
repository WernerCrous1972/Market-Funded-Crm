<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every AI-generated message — autonomous or reviewed — before/after send.
 * Once status leaves pending_review, the row is effectively immutable.
 */
class AiDraft extends Model
{
    use HasFactory, HasUuids;

    public const MODE_AUTONOMOUS    = 'AUTONOMOUS';
    public const MODE_REVIEWED      = 'REVIEWED';
    public const MODE_BULK_REVIEWED = 'BULK_REVIEWED';

    public const STATUS_PENDING_REVIEW    = 'pending_review';
    public const STATUS_APPROVED          = 'approved';
    public const STATUS_REJECTED          = 'rejected';
    public const STATUS_SENT              = 'sent';
    public const STATUS_FAILED            = 'failed';
    public const STATUS_BLOCKED_COMPLIANCE = 'blocked_compliance';

    public const CHANNEL_WHATSAPP = 'WHATSAPP';
    public const CHANNEL_EMAIL    = 'EMAIL';

    protected $fillable = [
        'person_id',
        'template_id',
        'mode',
        'channel',
        'model_used',
        'prompt_hash',
        'prompt_full',
        'draft_text',
        'final_text',
        'status',
        'compliance_check_id',
        'triggered_by_user_id',
        'triggered_by_event',
        'tokens_input',
        'tokens_output',
        'cost_cents',
        'sent_at',
    ];

    protected $casts = [
        'tokens_input'  => 'integer',
        'tokens_output' => 'integer',
        'cost_cents'    => 'integer',
        'sent_at'       => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OutreachTemplate::class, 'template_id');
    }

    public function complianceCheck(): BelongsTo
    {
        return $this->belongsTo(AiComplianceCheck::class, 'compliance_check_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
