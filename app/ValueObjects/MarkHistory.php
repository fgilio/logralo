<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * One goal's marks, reduced to what the streak and the photo rule need: which
 * days were marked, and which of those carried a photo.
 */
final readonly class MarkHistory
{
    /**
     * @param  list<array{date: string, full: bool}>  $entries  ascending by date
     */
    public function __construct(public array $entries) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /** @return list<string> */
    public function dates(): array
    {
        return array_values(
            collect($this->entries)->map(fn (array $entry): string => $entry['date'])->all()
        );
    }

    /**
     * How many ghost marks in a row end on a day, that day included. This is
     * the "2ᵃ vez seguida" line on a ghost feed card.
     */
    public function ghostRunEndingOn(string $date): int
    {
        $run = 0;

        foreach (array_reverse($this->entries) as $entry) {
            if ($entry['date'] > $date) {
                continue;
            }

            if ($entry['full']) {
                return $run;
            }

            $run++;
        }

        return $run;
    }

    /**
     * Whether each of the most recent marks before a day carried a photo,
     * newest first.
     *
     * @return list<bool>
     */
    public function recentFullnessBefore(string $date, int $limit): array
    {
        return array_values(
            collect($this->entries)
                ->reject(fn (array $entry): bool => $entry['date'] >= $date)
                ->reverse()
                ->take($limit)
                ->map(fn (array $entry): bool => $entry['full'])
                ->all()
        );
    }
}
