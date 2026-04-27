<?php

declare(strict_types=1);

namespace Database\Factories;

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
            'branch'       => 'Market Funded',
            'account_manager' => null,
            'notes_contacted'  => false,
            'imported_via_challenge' => false,
            'mtr_created_at' => now()->subDays(fake()->numberBetween(1, 365)),
        ];
    }
}
