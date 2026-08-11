<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Goal;
use App\Models\Mark;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Mark>
 */
final class MarkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $goal = Goal::factory();

        return [
            'goal_id' => $goal,
            'user_id' => fn (array $attributes): string => (string) Goal::query()->whereKey($attributes['goal_id'])->value('user_id'),
            'marked_on' => now()->toDateString(),
            'photo_key' => null,
        ];
    }

    /** A mark with proof: a photo key plus the dimensions the feed reserves. */
    public function withPhoto(): self
    {
        return $this->state(fn (array $attributes): array => [
            'photo_key' => 'photos/'.Str::ulid(),
            'photo_width' => 1080,
            'photo_height' => 1350,
        ]);
    }

    public function on(string $date): self
    {
        return $this->state(fn (array $attributes): array => ['marked_on' => $date]);
    }
}
