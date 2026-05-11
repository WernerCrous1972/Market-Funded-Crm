<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'mtr_branch_uuid',
        'name',
        'is_included',
        'persona_name',
        'persona_signoff',
        'customer_facing_name',
        'outreach_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_included'      => 'boolean',
            'outreach_enabled' => 'boolean',
        ];
    }

    /**
     * Resolved signoff line the AI must use when drafting on behalf of this
     * branch. Honours an explicit persona_signoff override; otherwise builds
     * "{persona_name} from {customer_facing_name|name}".
     *
     * Returns null when persona_name is unset — callers should treat that
     * as "branch is not draft-ready" and escalate.
     */
    public function resolvedSignoff(): ?string
    {
        if (! empty($this->persona_signoff)) {
            return (string) $this->persona_signoff;
        }
        if (empty($this->persona_name)) {
            return null;
        }
        $brand = $this->customer_facing_name ?: $this->name;

        return "{$this->persona_name} from {$brand}";
    }

    /**
     * Substitute the persona / brand tokens into any free-form text block.
     * Used by both the draft pipeline (system_prompt) and the compliance
     * pipeline (compliance_rules) so the same branch resolves to the same
     * persona in both places.
     *
     * Returns the input unchanged when persona_name is unset — callers
     * should guard with `resolvedSignoff() !== null` before using.
     */
    public function applyPersonaTokens(string $text): string
    {
        return strtr($text, [
            '{{ persona_name }}'    => (string) ($this->persona_name ?? ''),
            '{{ branch_brand }}'    => (string) ($this->customer_facing_name ?: $this->name),
            '{{ persona_signoff }}' => (string) ($this->resolvedSignoff() ?? ''),
        ]);
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function usersWithAccess(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branch_access')
            ->withPivot(['granted_at', 'granted_by']);
    }
}
