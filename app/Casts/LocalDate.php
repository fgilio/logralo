<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar day, not an instant.
 *
 * `marked_on` is the member's own local day, so it must round-trip as exactly
 * `Y-m-d`. Laravel's built-in date casts write through the connection's
 * datetime format, which Postgres quietly coerces back to a date but SQLite
 * stores verbatim — leaving `where('marked_on', '2026-08-11')` matching nothing
 * locally while passing in CI. This cast removes the difference.
 *
 * @implements CastsAttributes<CarbonImmutable, CarbonImmutable|string>
 */
final class LocalDate implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }

        return CarbonImmutable::parse((string) $value)->toDateString();
    }
}
