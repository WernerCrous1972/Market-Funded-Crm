<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonMetric extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'person_metrics';

    protected $fillable = [
        'person_id',
        'total_deposits_cents',
        'total_withdrawals_cents',
        'net_deposits_cents',
        'total_challenge_purchases_cents',
        'deposit_count',
        'withdrawal_count',
        'challenge_purchase_count',
        'first_deposit_at',
        'last_deposit_at',
        'last_withdrawal_at',
        'last_transaction_at',
        'days_since_last_deposit',
        'days_since_last_login',
        'has_markets',
        'has_capital',
        'has_academy',
        'deposits_mtd_cents',
        'withdrawals_mtd_cents',
        'challenge_purchases_mtd_cents',
        'refreshed_at',
    ];

    protected $casts = [
        'total_deposits_cents'            => 'integer',
        'total_withdrawals_cents'         => 'integer',
        'net_deposits_cents'              => 'integer',
        'total_challenge_purchases_cents' => 'integer',
        'deposit_count'                   => 'integer',
        'withdrawal_count'                => 'integer',
        'challenge_purchase_count'        => 'integer',
        'first_deposit_at'                => 'datetime',
        'last_deposit_at'                 => 'datetime',
        'last_withdrawal_at'              => 'datetime',
        'last_transaction_at'             => 'datetime',
        'days_since_last_deposit'         => 'integer',
        'days_since_last_login'           => 'integer',
        'has_markets'                     => 'boolean',
        'has_capital'                     => 'boolean',
        'has_academy'                     => 'boolean',
        'deposits_mtd_cents'              => 'integer',
        'withdrawals_mtd_cents'           => 'integer',
        'challenge_purchases_mtd_cents'   => 'integer',
        'refreshed_at'                    => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    // ── Formatted accessors (USD display) ─────────────────────────────────────

    public function getTotalDepositsUsdAttribute(): float
    {
        return $this->total_deposits_cents / 100;
    }

    public function getTotalWithdrawalsUsdAttribute(): float
    {
        return $this->total_withdrawals_cents / 100;
    }

    public function getNetDepositsUsdAttribute(): float
    {
        return $this->net_deposits_cents / 100;
    }

    public function getTotalChallengePurchasesUsdAttribute(): float
    {
        return $this->total_challenge_purchases_cents / 100;
    }

    public function getDepositsMtdUsdAttribute(): float
    {
        return $this->deposits_mtd_cents / 100;
    }

    public function getWithdrawalsMtdUsdAttribute(): float
    {
        return $this->withdrawals_mtd_cents / 100;
    }
}
