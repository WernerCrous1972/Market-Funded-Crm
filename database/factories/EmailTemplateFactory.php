<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    public function definition(): array
    {
        return [
            'name'      => $this->faker->sentence(4),
            'subject'   => $this->faker->sentence(6),
            'body_html' => '<p>Hello {{first_name}},</p><p>' . $this->faker->paragraph() . '</p>',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
