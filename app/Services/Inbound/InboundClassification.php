<?php

declare(strict_types=1);

namespace App\Services\Inbound;

/**
 * Result of one InboundClassifier::classify() call.
 *
 * `intent` is always one of the allowed values from
 * `config/outreach_inbound.intents` — anything else is coerced to `unclear`.
 * `confidence` is clamped to [0, 100].
 */
final readonly class InboundClassification
{
    public function __construct(
        public string $intent,
        public int    $confidence,
        public string $model_used,
        public int    $tokens_input,
        public int    $tokens_output,
        public int    $cost_cents,
    ) {}

    public function isSafe(): bool
    {
        return in_array(
            $this->intent,
            (array) config('outreach_inbound.safe_intents', []),
            true,
        );
    }

    public function meetsThreshold(): bool
    {
        $threshold = (int) config('ai.inbound_confidence_threshold', 75);
        return $this->confidence >= $threshold;
    }

    public function shouldAutoReply(): bool
    {
        return $this->isSafe() && $this->meetsThreshold();
    }
}
