<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;
use Throwable;

/**
 * All models in the configured chain (primary + fallbacks) failed to respond.
 *
 * Carries the per-model attempt log so callers (or the AI Ops dashboard) can
 * see exactly what was tried and why each attempt failed.
 */
class ModelRouterException extends RuntimeException
{
    /**
     * @param  array<int, array{model: string, error: string}>  $attempts
     */
    public function __construct(
        string $message,
        public readonly array $attempts = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
