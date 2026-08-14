<?php

declare(strict_types=1);

namespace App\ValueObjects;

use Illuminate\Support\Str;

/**
 * One member's line in the monthly standings.
 *
 * The score is completion of that member's own possible marks so far this
 * month, a full mark worth 1 and a ghost mark worth 0.5. Dividing by each
 * member's own possible marks is what keeps a 2-goal member competitive with
 * a 5-goal one.
 */
final readonly class Standing
{
    public function __construct(
        public string $userId,
        public string $name,
        public int $fullMarks,
        public int $ghostMarks,
        public int $possibleMarks,
        public int $rank,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: (string) $data['user_id'],
            name: (string) $data['name'],
            fullMarks: (int) $data['full_marks'],
            ghostMarks: (int) $data['ghost_marks'],
            possibleMarks: (int) $data['possible_marks'],
            rank: (int) $data['rank'],
        );
    }

    public function points(): float
    {
        return $this->fullMarks + ($this->ghostMarks * 0.5);
    }

    public function score(): float
    {
        if ($this->possibleMarks === 0) {
            return 0.0;
        }

        return $this->points() / $this->possibleMarks;
    }

    public function percentage(): float
    {
        return round($this->score() * 100, 1);
    }

    /**
     * The score as every screen writes it: a comma for the decimal, and no
     * trailing zero on a round number.
     *
     * Here rather than in the templates because the share card is drawn by GD
     * from a value object, so the standings row, the recap card and the image
     * that lands in WhatsApp have exactly one place to agree.
     */
    public function percentageLabel(): string
    {
        return Str::of(number_format($this->percentage(), 1, ',', '.'))
            ->rtrim('0')
            ->rtrim(',')
            ->append('%')
            ->toString();
    }

    /** The solid segment of the standings bar. */
    public function fullPercentage(): float
    {
        return $this->share($this->fullMarks);
    }

    /** The hatched segment, drawn at the half value ghost marks are worth. */
    public function ghostPercentage(): float
    {
        return $this->share($this->ghostMarks * 0.5);
    }

    public function withRank(int $rank): self
    {
        return new self(
            userId: $this->userId,
            name: $this->name,
            fullMarks: $this->fullMarks,
            ghostMarks: $this->ghostMarks,
            possibleMarks: $this->possibleMarks,
            rank: $rank,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'name' => $this->name,
            'full_marks' => $this->fullMarks,
            'ghost_marks' => $this->ghostMarks,
            'possible_marks' => $this->possibleMarks,
            'rank' => $this->rank,
        ];
    }

    private function share(float $marks): float
    {
        if ($this->possibleMarks === 0) {
            return 0.0;
        }

        return round(min($marks / $this->possibleMarks, 1.0) * 100, 1);
    }
}
