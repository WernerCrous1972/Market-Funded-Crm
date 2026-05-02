<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'                       => fake()->name(),
            'email'                      => fake()->unique()->safeEmail(),
            'email_verified_at'          => now(),
            'password'                   => static::$password ??= Hash::make('password'),
            'remember_token'             => Str::random(10),
            'role'                       => 'SALES_AGENT',
            // Phase B permission defaults — must match DB defaults (false)
            'is_super_admin'             => false,
            'assigned_only'              => false,
            'can_view_client_financials' => false,
            'can_view_branch_financials' => false,
            'can_view_health_scores'     => false,
            'can_make_notes'             => false,
            'can_send_whatsapp'          => false,
            'can_send_email'             => false,
            'can_create_email_campaigns' => false,
            'can_edit_clients'           => false,
            'can_assign_clients'         => false,
            'can_create_tasks'           => false,
            'can_assign_tasks_to_others' => false,
            'can_export'                 => false,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
