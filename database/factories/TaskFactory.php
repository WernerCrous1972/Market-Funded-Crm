<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Person;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'person_id'           => Person::factory(),
            'assigned_to_user_id' => User::factory(),
            'created_by_user_id'  => null,
            'auto_assigned'       => false,
            'task_type'           => Task::TYPE_GENERAL,
            'title'               => $this->faker->sentence(5),
            'description'         => null,
            'priority'            => $this->faker->randomElement(['LOW', 'MEDIUM', 'HIGH', 'URGENT']),
            'due_at'              => $this->faker->dateTimeBetween('now', '+30 days'),
            'completed_at'        => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(['completed_at' => now()]);
    }

    public function overdue(): static
    {
        return $this->state([
            'due_at'       => now()->subDays(2),
            'completed_at' => null,
        ]);
    }
}
