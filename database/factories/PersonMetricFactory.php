<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PersonMetric>
 */
class PersonMetricFactory extends Factory
{
    public function definition(): array
    {
        $deposits    = fake()->numberBetween(0, 1_000_000);
        $withdrawals = fake()->numberBetween(0, $deposits);

        return [
            'total_deposits_cents'            => $deposits,
            'total_withdrawals_cents'         => $withdrawals,
            'net_deposits_cents'              => $deposits - $withdrawals,
            'total_challenge_purchases_cents' => 0,
            'deposit_count'                   => fake()->numberBetween(0, 20),
            'withdrawal_count'                => fake()->numberBetween(0, 10),
            'challenge_purchase_count'        => 0,
            'first_deposit_at'                => $deposits > 0 ? now()->subDays(90) : null,
            'last_deposit_at'                 => $deposits > 0 ? now()->subDays(5) : null,
            'last_withdrawal_at'              => $withdrawals > 0 ? now()->subDays(10) : null,
            'last_transaction_at'             => $deposits > 0 ? now()->subDays(5) : null,
            'days_since_last_deposit'         => $deposits > 0 ? 5 : null,
            'days_since_last_login'           => fake()->optional()->numberBetween(0, 60),
            'has_markets'                     => true,
            'has_capital'                     => false,
            'has_academy'                     => false,
            'deposits_mtd_cents'              => 0,
            'withdrawals_mtd_cents'           => 0,
            'challenge_purchases_mtd_cents'   => 0,
            'refreshed_at'                    => now(),
            'health_score'                    => null,
            'health_grade'                    => null,
            'health_score_breakdown'          => null,
            'health_score_calculated_at'      => null,
        ];
    }
}
