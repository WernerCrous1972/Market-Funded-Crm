<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone_e164',
        'phone_country_code',
        'country',
        'contact_type',
        'lead_status',
        'lead_source',
        'affiliate',
        'branch',
        'account_manager',
        'became_active_client_at',
        'last_online_at',
        'notes_contacted',
        'duplicate_of_person_id',
        'mtr_last_synced_at',
        'mtr_created_at',
        'mtr_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'became_active_client_at' => 'datetime',
            'last_online_at'          => 'datetime',
            'mtr_last_synced_at'      => 'datetime',
            'mtr_created_at'          => 'datetime',
            'mtr_updated_at'          => 'datetime',
            'notes_contacted'         => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function tradingAccounts(): HasMany
    {
        return $this->hasMany(TradingAccount::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->orderByDesc('occurred_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class)->orderByDesc('created_at');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('due_at');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'duplicate_of_person_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(Person::class, 'duplicate_of_person_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeLeads(Builder $query): Builder
    {
        return $query->where('contact_type', 'LEAD');
    }

    public function scopeClients(Builder $query): Builder
    {
        return $query->where('contact_type', 'CLIENT');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('contact_type', 'CLIENT')
            ->whereNotNull('became_active_client_at');
    }

    public function scopeByPipeline(Builder $query, string $pipeline): Builder
    {
        return $query->whereHas('tradingAccounts', fn (Builder $q) =>
            $q->where('pipeline', $pipeline)
        );
    }

    public function scopeByBranch(Builder $query, string $branch): Builder
    {
        return $query->where('branch', $branch);
    }

    public function scopeInactiveSince(Builder $query, int $days): Builder
    {
        return $query->where('last_online_at', '<', now()->subDays($days))
            ->orWhereNull('last_online_at');
    }

    // ── Computed attributes ──────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getTotalDepositsCentsAttribute(): int
    {
        return (int) $this->transactions()
            ->where('category', 'EXTERNAL_DEPOSIT')
            ->sum('amount_cents');
    }

    public function getTotalWithdrawalsCentsAttribute(): int
    {
        return (int) $this->transactions()
            ->where('category', 'EXTERNAL_WITHDRAWAL')
            ->sum('amount_cents');
    }

    public function getTotalChallengePurchasesCentsAttribute(): int
    {
        return (int) $this->transactions()
            ->where('category', 'CHALLENGE_PURCHASE')
            ->sum('amount_cents');
    }

    public function getNetDepositsCentsAttribute(): int
    {
        return $this->total_deposits_cents - $this->total_withdrawals_cents;
    }

    public function getLastExternalDepositAtAttribute(): ?\Carbon\Carbon
    {
        $occurred = $this->transactions()
            ->where('category', 'EXTERNAL_DEPOSIT')
            ->max('occurred_at');

        return $occurred ? \Carbon\Carbon::parse($occurred) : null;
    }

    public function getDaysSinceLastLoginAttribute(): ?int
    {
        return $this->last_online_at
            ? (int) $this->last_online_at->diffInDays(now())
            : null;
    }

    // ── Mutators ─────────────────────────────────────────────────────────────

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

    // ── Upgrade-only contact_type guard ──────────────────────────────────────

    public function upgradeToClient(): void
    {
        if ($this->contact_type !== 'CLIENT') {
            $this->contact_type = 'CLIENT';
        }
    }
}
