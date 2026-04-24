<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'mtr_offer_uuid',
        'name',
        'pipeline',
        'is_demo',
        'is_prop_challenge',
        'branch_uuid',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'is_demo'           => 'boolean',
            'is_prop_challenge' => 'boolean',
            'raw_data'          => 'array',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function tradingAccounts(): HasMany
    {
        return $this->hasMany(TradingAccount::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopePropChallenges($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_prop_challenge', true);
    }

    public function scopeByPipeline($query, string $pipeline): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('pipeline', $pipeline);
    }
}
