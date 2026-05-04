<?php

declare(strict_types=1);

namespace App\Models;

use App\Events\LeadConverted;
use App\Helpers\CountryHelper;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Branch;
use App\Models\User;

class Person extends Model
{
    use HasFactory, HasUuids;

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
        'branch_id',
        'account_manager',
        'account_manager_user_id',
        'became_active_client_at',
        'last_online_at',
        'notes_contacted',
        'duplicate_of_person_id',
        'mtr_last_synced_at',
        'mtr_created_at',
        'mtr_updated_at',
        'mtr_account_uuid',
        'imported_via_challenge',
    ];

    protected $casts = [
        'became_active_client_at' => 'datetime',
        'last_online_at'          => 'datetime',
        'mtr_last_synced_at'      => 'datetime',
        'mtr_created_at'          => 'datetime',
        'mtr_updated_at'          => 'datetime',
        'notes_contacted'         => 'boolean',
        'imported_via_challenge'  => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function tradingAccounts(): HasMany
    {
        return $this->hasMany(TradingAccount::class);
    }

    public function whatsappMessages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class)->orderBy('created_at');
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

    public function metrics(): HasOne
    {
        return $this->hasOne(PersonMetric::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'duplicate_of_person_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(Person::class, 'duplicate_of_person_id');
    }

    public function branchModel(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeLeads($query)
    {
        return $query->where('contact_type', 'LEAD');
    }

    public function scopeClients($query)
    {
        return $query->where('contact_type', 'CLIENT');
    }

    public function scopeActive($query)
    {
        return $query->whereNotNull('became_active_client_at');
    }

    public function scopeByPipeline($query, string $pipeline)
    {
        return $query->whereHas('tradingAccounts', fn ($q) => $q->where('pipeline', $pipeline));
    }

    public function scopeInactiveSince($query, int $days)
    {
        return $query->where('last_online_at', '<', now()->subDays($days));
    }

    // ── Mutators ──────────────────────────────────────────────────────────────

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Returns the pipelines this person has traded in, e.g. ['MFU_MARKETS', 'MFU_CAPITAL']
     * Reads from person_metrics if loaded, otherwise queries trading_accounts.
     */
    public function getPipelinesAttribute(): array
    {
        if ($this->relationLoaded('metrics') && $this->metrics) {
            $pipes = [];
            if ($this->metrics->has_markets) $pipes[] = 'MFU_MARKETS';
            if ($this->metrics->has_capital) $pipes[] = 'MFU_CAPITAL';
            if ($this->metrics->has_academy) $pipes[] = 'MFU_ACADEMY';
            return $pipes;
        }

        return $this->tradingAccounts()
            ->distinct()
            ->pluck('pipeline')
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Country flag + name for display.
     */
    public function getCountryDisplayAttribute(): string
    {
        return CountryHelper::display($this->country);
    }

    /**
     * WhatsApp link for the person's phone number.
     */
    public function getWhatsappLinkAttribute(): ?string
    {
        if (! $this->phone_e164) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $this->phone_e164);

        return "https://wa.me/{$digits}";
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Upgrade a LEAD to CLIENT. Enforces upgrade-only rule.
     * Returns true if an upgrade occurred.
     */
    public function upgradeToClient(): bool
    {
        if ($this->contact_type === 'CLIENT') {
            return false;
        }

        $this->contact_type = 'CLIENT';
        $this->save();

        Activity::record(
            personId: $this->id,
            type: Activity::TYPE_STATUS_CHANGED,
            description: "Upgraded to CLIENT",
        );

        broadcast(new LeadConverted($this));

        return true;
    }
}
