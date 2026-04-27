<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement(['DEPOSIT', 'WITHDRAWAL']);

        return [
            'mtr_transaction_uuid' => fake()->uuid(),
            'type'                 => $type,
            'amount_cents'         => fake()->numberBetween(1000, 500_000),
            'currency'             => 'USD',
            'status'               => 'DONE',
            'gateway_name'         => 'Test Gateway',
            'offer_name'           => null,
            'remark'               => null,
            'occurred_at'          => now()->subDays(fake()->numberBetween(1, 180)),
            'synced_at'            => now(),
            'pipeline'             => 'MFU_MARKETS',
            'category'             => $type === 'DEPOSIT' ? 'EXTERNAL_DEPOSIT' : 'EXTERNAL_WITHDRAWAL',
        ];
    }
}
