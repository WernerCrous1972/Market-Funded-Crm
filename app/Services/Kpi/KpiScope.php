<?php

declare(strict_types=1);

namespace App\Services\Kpi;

/**
 * Value object describing the filter scope for a KPI query.
 *
 *   KpiScope::company()           — no filter (admin / manager view)
 *   KpiScope::branch($branchId)   — restrict to one branch
 *   KpiScope::agent($userId)      — restrict to people assigned to this user
 *
 * KpiQuery applies the scope by joining people on the appropriate column:
 *   - branch  → people.branch_id = ?
 *   - agent   → people.account_manager_user_id = ?
 *
 * For agent scope, the query MUST also join on people because
 * `transactions` itself has no agent column — assignment lives on the
 * person.
 */
final class KpiScope
{
    private function __construct(
        public readonly string $type,
        public readonly ?string $id = null,
    ) {}

    public static function company(): self
    {
        return new self('company');
    }

    public static function branch(string $branchId): self
    {
        return new self('branch', $branchId);
    }

    public static function agent(string $userId): self
    {
        return new self('agent', $userId);
    }

    public function isCompany(): bool
    {
        return $this->type === 'company';
    }
}
