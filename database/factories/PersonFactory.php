<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Person>
 */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name'   => fake()->firstName(),
            'last_name'    => fake()->lastName(),
            'email'        => fake()->unique()->safeEmail(),
            'phone_e164'   => null,
            'country'      => 'South Africa',
            'contact_type' => fake()->randomElement(['LEAD', 'CLIENT']),
            'lead_source'  => fake()->randomElement(['WEBSITE', 'REFERRAL', 'SOCIAL', null]),
            'lead_status'  => null,
            'affiliate'    => null,
            'branch'                  => 'Market Funded',
            'branch_id'               => fn () => $this->draftReadyBranchId(),
            'account_manager'         => null,
            'account_manager_user_id' => null,
            'notes_contacted'  => false,
            'imported_via_challenge' => false,
            'mtr_created_at' => now()->subDays(fake()->numberBetween(1, 365)),
        ];
    }

    /**
     * Test-only helper — production has Persons with a branch attached and
     * the AI draft path now refuses to draft without one. To avoid every
     * test setting up a branch by hand, we lazily create / reuse a single
     * "Test Branch" row that's persona-ready.
     */
    private function draftReadyBranchId(): string
    {
        return Branch::firstOrCreate(
            ['name' => 'Test Branch'],
            [
                'mtr_branch_uuid'      => 'test-branch-uuid',
                'is_included'          => true,
                'persona_name'         => 'Alex',
                'customer_facing_name' => 'Test Branch',
                'outreach_enabled'     => true,
            ],
        )->id;
    }

    /**
     * State: person attached to a branch that REFUSES outreach. Use in tests
     * that exercise BranchNotDraftReadyException paths.
     */
    public function withOutreachDisabledBranch(): self
    {
        return $this->state(function () {
            $branch = Branch::firstOrCreate(
                ['name' => 'Test Disabled Branch'],
                [
                    'mtr_branch_uuid'  => 'test-disabled-uuid',
                    'is_included'      => true,
                    'outreach_enabled' => false,
                ],
            );

            return ['branch_id' => $branch->id];
        });
    }

    /**
     * State: person with NO branch attached. Use to test the missing_branch
     * escalation path.
     */
    public function withoutBranch(): self
    {
        return $this->state(fn () => ['branch_id' => null]);
    }
}
