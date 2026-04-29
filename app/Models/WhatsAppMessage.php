<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'person_id',
        'direction',
        'wa_message_id',
        'template_id',
        'body_text',
        'media_url',
        'status',
        'error_code',
        'error_message',
        'agent_key',
        'sent_by_user_id',
        'conversation_window_expires_at',
    ];

    protected $casts = [
        'conversation_window_expires_at' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'template_id');
    }

    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
