<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReactionEmoji;
use App\Models\Mark;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reaction>
 */
final class ReactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mark_id' => Mark::factory(),
            'user_id' => User::factory(),
            'emoji' => fake()->randomElement(ReactionEmoji::cases()),
        ];
    }
}
