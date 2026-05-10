<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hard regex blocklist + AI self-check verdict for a single draft.
 * Both layers contribute flags to the same jsonb array; a single
 * passed = false blocks the send.
 */
class AiComplianceCheck extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false; // only created_at on this table

    protected $fillable = [
        'draft_id',
        'model_used',
        'passed',
        'flags',
        'verdict_text',
        'tokens_input',
        'tokens_output',
        'cost_cents',
        'created_at',
    ];

    protected $casts = [
        'passed'        => 'boolean',
        'flags'         => 'array',
        'tokens_input'  => 'integer',
        'tokens_output' => 'integer',
        'cost_cents'    => 'integer',
        'created_at'    => 'datetime',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(AiDraft::class, 'draft_id');
    }
}
