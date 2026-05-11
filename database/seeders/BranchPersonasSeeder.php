<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * Branch persona seeding.
 *
 * Each branch is its own consumer-facing brand. The AI signs off with a
 * branch-specific persona; "Alex from Market Funded" only applies to the
 * Market Funded branch, never to clients of QuickTrade, Henderson, etc.
 *
 * Branches not listed here keep outreach_enabled=false (the migration
 * default), so we cannot accidentally draft for an un-personified branch.
 *
 * Update this seeder + re-run as new branches come online via Wave 2a.
 */
final class BranchPersonasSeeder extends Seeder
{
    public function run(): void
    {
        $personas = [
            // Active branches — outreach allowed
            'Market Funded' => [
                'persona_name'         => 'Alex',
                'customer_facing_name' => 'Market Funded',
                'persona_signoff'      => null,
                'outreach_enabled'     => true,
            ],
            'QuickTrade' => [
                // Werner: persona TBD, but signoff brand must be "QuickTrade.world"
                'persona_name'         => 'Jordan',
                'customer_facing_name' => 'QuickTrade.world',
                'persona_signoff'      => null,
                'outreach_enabled'     => true,
            ],

            // Explicitly NOT customer-facing — outreach blocked by policy
            'MTT-test' => [
                'persona_name'         => null,
                'customer_facing_name' => null,
                'persona_signoff'      => null,
                'outreach_enabled'     => false,
            ],
            'NO Withdrawal Branch' => [
                'persona_name'         => null,
                'customer_facing_name' => null,
                'persona_signoff'      => null,
                'outreach_enabled'     => false,
            ],
        ];

        foreach ($personas as $name => $attrs) {
            $branch = Branch::where('name', $name)->first();
            if (! $branch) {
                $this->command?->warn("Branch '{$name}' not found — skipped. Run mtr:sync first.");
                continue;
            }
            $branch->fill($attrs)->save();
        }
    }
}
