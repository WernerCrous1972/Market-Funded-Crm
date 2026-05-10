<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;

/**
 * Thrown when the orchestrator can't proceed — typically when the cost
 * ceiling guard refuses the call. The thrown message names the guard state
 * (`pause_all` or `pause_autonomous`) so callers can produce a sensible
 * error to the agent.
 */
class AiOrchestratorException extends RuntimeException
{
}
