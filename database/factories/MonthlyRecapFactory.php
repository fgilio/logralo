<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MonthlyRecap;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyRecap>
 */
final class MonthlyRecapFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $month = CarbonImmutable::now()->startOfMonth()->subMonth();

        return [
            'month' => $month->toDateString(),
            'posted_on' => $month->endOfMonth()->toDateString(),
            'best_streak_days' => 0,
            'standings' => [],
        ];
    }
}
