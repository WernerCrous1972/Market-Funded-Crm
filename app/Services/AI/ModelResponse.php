<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Result of a successful ModelRouter::call(). Immutable value object.
 *
 * Failed calls do NOT return this — ModelRouter throws ModelRouterException
 * (or its subclasses) when the entire fallback chain fails. A "successful"
 * call means *some* model in the chain returned a response, even if the
 * primary failed first; check `$model_used` to see which one actually answered.
 */
final class ModelResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $model_used,
        public readonly int $tokens_input,
        public readonly int $tokens_output,
        public readonly int $cost_cents,
        public readonly bool $used_fallback,
        public readonly array $raw = [],
    ) {}
}
