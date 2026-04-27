<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCampaign extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'created_by_user_id',
        'email_template_id',
        'name',
        'subject_override',
        'status',
        'recipient_mode',
        'recipient_filter_key',
        'recipient_manual_ids',
        'scheduled_at',
        'started_at',
        'completed_at',
        'recipient_count',
        'sent_count',
        'opened_count',
        'clicked_count',
        'bounced_count',
        'unsubscribed_count',
        'from_name',
        'from_email',
    ];

    protected $casts = [
        'recipient_manual_ids' => 'array',
        'scheduled_at'         => 'datetime',
        'started_at'           => 'datetime',
        'completed_at'         => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class, 'campaign_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class, 'campaign_id');
    }

    public function getOpenRateAttribute(): float
    {
        if ($this->sent_count === 0) return 0.0;
        return round($this->opened_count / $this->sent_count * 100, 1);
    }

    public function getClickRateAttribute(): float
    {
        if ($this->sent_count === 0) return 0.0;
        return round($this->clicked_count / $this->sent_count * 100, 1);
    }

    public function isDraft(): bool   { return $this->status === 'DRAFT'; }
    public function isSent(): bool    { return $this->status === 'SENT'; }
    public function isSending(): bool { return $this->status === 'SENDING'; }
}
