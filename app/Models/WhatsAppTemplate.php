<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppTemplate extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'name',
        'category',
        'language_code',
        'body_text',
        'variables',
        'department',
        'status',
        'meta_template_id',
        'approved_at',
    ];

    protected $casts = [
        'variables'   => 'array',
        'approved_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'template_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }
}
