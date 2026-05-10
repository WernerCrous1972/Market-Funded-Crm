<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inbound WhatsApp reply that was routed through the AI inbound flow.
 *
 * One row per classification — not every WhatsAppMessage gets one, only the
 * ones the inbound listener decides to classify. Records the routing
 * decision so the threshold can be tuned from real data.
 */
class OutreachInboundMessage extends Model
{
    use HasFactory, HasUuids;

    public const ROUTING_AUTO_REPLIED        = 'auto_replied';
    public const ROUTING_ESCALATED_TO_AGENT  = 'escalated_to_agent';
    public const ROUTING_ESCALATED_TO_HENRY  = 'escalated_to_henry';

    public $timestamps = false;

    protected $fillable = [
        'whatsapp_message_id',
        'person_id',
        'intent',
        'confidence',
        'routing',
        'auto_reply_draft_id',
        'assigned_to_user_id',
        'created_at',
    ];

    protected $casts = [
        'confidence' => 'integer',
        'created_at' => 'datetime',
    ];

    public function whatsappMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessage::class, 'whatsapp_message_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function autoReplyDraft(): BelongsTo
    {
        return $this->belongsTo(AiDraft::class, 'auto_reply_draft_id');
    }

    public function assignedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
