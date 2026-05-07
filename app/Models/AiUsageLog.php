<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily-aggregated AI spend per (date, task_type, model). Written to by
 * ModelRouter on every successful call (UPSERT on the unique triple).
 *
 * Read by CostCeilingGuard to enforce $300/$500 monthly caps.
 */
class AiUsageLog extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ai_usage_log';

    protected $fillable = [
        'date',
        'task_type',
        'model',
        'call_count',
        'tokens_input',
        'tokens_output',
        'cost_cents',
    ];

    protected $casts = [
        'date'          => 'date',
        'call_count'    => 'integer',
        'tokens_input'  => 'integer',
        'tokens_output' => 'integer',
        'cost_cents'    => 'integer',
    ];
}
