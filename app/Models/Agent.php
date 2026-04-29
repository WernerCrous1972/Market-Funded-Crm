<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'key',
        'name',
        'department',
        'system_prompt',
        'is_active',
        'escalation_rules',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'escalation_rules' => 'array',
    ];

    public function whatsappMessages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'agent_key', 'key');
    }
}
