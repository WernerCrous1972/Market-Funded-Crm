<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    // Transactions are immutable once synced — no updated_at needed
    public $timestamps = false;

    protected $fillable = [
        'person_id',
        'trading_account_id',
        'mtr_transaction_uuid',
        'type',
        'amount_cents',
        'currency',
        'status',
        'gateway_name',
        'remark',
        'occurred_at',
        'synced_at',
        'pipeline',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'occurred_at'  => 'datetime',
            'synced_at'    => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeDeposits(Builder $query): Builder
    {
        return $query->where('type', 'DEPOSIT');
    }

    public function scopeWithdrawals(Builder $query): Builder
    {
        return $query->where('type', 'WITHDRAWAL');
    }

    public function scopeDone(Builder $query): Builder
    {
        return $query->where('status', 'DONE');
    }

    public function scopeByPipeline(Builder $query, string $pipeline): Builder
    {
        return $query->where('pipeline', $pipeline);
    }

    public function scopeInPeriod(Builder $query, \Carbon\Carbon $from, \Carbon\Carbon $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }

    // ── Computed attributes ──────────────────────────────────────────────────

    public function getAmountUsdAttribute(): float
    {
        return round($this->amount_cents / 100, 2);
    }
}
