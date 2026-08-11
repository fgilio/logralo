<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    private static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::$password ??= Hash::make('password'),
            'timezone' => 'America/Montevideo',
            'remember_token' => Str::random(10),
        ];
    }

    /** A member who has opened their magic link but not chosen a password. */
    public function withoutPassword(): self
    {
        return $this->state(fn (array $attributes): array => ['password' => null]);
    }

    public function withMagicLink(string $plainToken): self
    {
        return $this->state(fn (array $attributes): array => [
            'magic_link_token' => hash('sha256', $plainToken),
            'magic_link_sent_at' => now(),
            'magic_link_used_at' => null,
        ]);
    }

    public function inTimezone(string $timezone): self
    {
        return $this->state(fn (array $attributes): array => ['timezone' => $timezone]);
    }
}
