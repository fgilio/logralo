<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CloseMonth;
use App\Models\Mark;
use App\Models\MonthlyRecap;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Closes every month that is done and not yet recapped.
 *
 * Runs hourly rather than monthly: the group spans timezones, so the moment a
 * month is finally over for everybody does not line up with any one clock, and
 * an hourly sweep needs no cron arithmetic to catch it.
 */
#[Signature('logralo:close-months {--month= : A single YYYY-MM to close instead of sweeping}')]
#[Description('Freeze finished months and post their recap card into the feed')]
final class CloseMonthsCommand extends Command
{
    public function handle(CloseMonth $closeMonth): int
    {
        $closed = 0;

        try {
            foreach ($this->months() as $month) {
                // Scoped: Context is process-global, so without this the recap
                // id of a month that closed stays set on the log line of the
                // next month, which did nothing.
                $recap = Context::scope(fn (): ?MonthlyRecap => $closeMonth->handle($month));

                if ($recap instanceof MonthlyRecap && $recap->wasRecentlyCreated) {
                    $closed++;
                    $this->components->info("Closed {$month->format('Y-m')}.");
                }
            }

            Context::add('logralo.months_closed', $closed);
            Context::add('logralo.outcome', 'completed');

            $this->components->info($closed === 0 ? 'No months to close.' : "{$closed} month(s) closed.");

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('month.sweep.handled');
        }
    }

    /**
     * Every month from the first mark ever made up to last month, oldest first.
     * The current month is never a candidate.
     *
     * @return list<CarbonImmutable>
     */
    private function months(): array
    {
        $explicit = $this->option('month');

        if (is_string($explicit) && $explicit !== '') {
            return [CarbonImmutable::parse($explicit.'-01')->startOfMonth()];
        }

        $firstMark = Mark::query()->min('marked_on');

        if ($firstMark === null) {
            return [];
        }

        $month = CarbonImmutable::parse((string) $firstMark)->startOfMonth();
        $lastCandidate = CarbonImmutable::now()->startOfMonth()->subMonth();
        $months = [];

        while ($month->lessThanOrEqualTo($lastCandidate)) {
            $months[] = $month;
            $month = $month->addMonth();
        }

        return $months;
    }
}
