<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingAccount extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'person_id',
        'mtr_account_uuid',
        'mtr_login',
        'offer_id',
        'pipeline',
        'is_demo',
        'is_active',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'is_demo'   => 'boolean',
            'is_active' => 'boolean',
            'opened_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_demo', false)->where('is_active', true);
    }

    public function scopeByPipeline(Builder $query, string $pipeline): Builder
    {
        return $query->where('pipeline', $pipeline);
    }
}
