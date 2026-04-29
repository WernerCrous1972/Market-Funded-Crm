<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WhatsAppMessage>
 */
class WhatsAppMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'person_id'  => Person::factory(),
            'direction'  => fake()->randomElement(['OUTBOUND', 'INBOUND']),
            'wa_message_id' => 'wamid.' . strtoupper(fake()->unique()->lexify('??????????')),
            'template_id'   => null,
            'body_text'     => fake()->sentence(),
            'media_url'     => null,
            'status'        => fake()->randomElement(['SENT', 'DELIVERED', 'READ', 'RECEIVED']),
            'error_code'    => null,
            'error_message' => null,
            'agent_key'     => null,
            'sent_by_user_id' => null,
            'conversation_window_expires_at' => null,
        ];
    }
}
