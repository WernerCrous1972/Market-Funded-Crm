<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use App\Models\Person;
use RuntimeException;

/**
 * Thrown when a draft is requested for a person whose branch context is
 * not ready for outreach. Three cases:
 *
 *   1. person.branch_id is null               → reason = 'missing_branch'
 *   2. branch.outreach_enabled = false        → reason = 'outreach_disabled'
 *   3. branch.persona_name is null            → reason = 'persona_unset'
 *
 * OutreachOrchestrator catches this and routes a Telegram alert to Henry
 * so Werner can investigate the underlying data problem.
 */
final class BranchNotDraftReadyException extends RuntimeException
{
    public const REASON_MISSING_BRANCH    = 'missing_branch';
    public const REASON_OUTREACH_DISABLED = 'outreach_disabled';
    public const REASON_PERSONA_UNSET     = 'persona_unset';

    public function __construct(
        public readonly Person $person,
        public readonly string $reason,
        public readonly ?string $branchName = null,
    ) {
        parent::__construct("Branch not draft-ready for person {$person->id}: {$reason}");
    }
}
