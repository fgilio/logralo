<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
final class GoalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'emoji' => fake()->randomElement(['🏋️', '🏃', '📚', '🧘', '💧', '🥗']),
            'name' => fake()->randomElement(['Gimnasio', 'Correr', 'Leer', 'Meditar', 'Tomar agua', 'Comer bien']),
            'position' => fake()->numberBetween(1, 5),
        ];
    }

    public function archived(): self
    {
        return $this->state(fn (array $attributes): array => ['archived_at' => now()]);
    }
}
