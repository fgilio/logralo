<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Goal;
use App\Models\User;
use App\Notifications\StreakAboutToBreak;
use App\Queries\GoalHistory;
use App\Services\StreakCalculator;
use App\ValueObjects\MarkHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One member, one look at whether their oldest open day is about to take a
 * streak down with it.
 *
 * The bar is deliberately high. An unmarked goal is not news, and a nudge on
 * every one of them arrives every evening for anybody who does not mark all
 * five. A run that ends when the grace window shuts is rare, and it cannot be
 * recovered the next day.
 */
final readonly class SendStreakReminder
{
    public function __construct(
        private GoalHistory $history,
        private StreakCalculator $streaks,
    ) {}

    /** @return bool whether this member was nudged */
    public function handle(User $member): bool
    {
        Context::add('logralo.user_id', $member->id);

        try {
            $sent = $this->remind($member);

            Context::add('logralo.outcome', $sent ? 'sent' : 'skipped');

            return $sent;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('push.reminder.handled');
        }
    }

    private function remind(User $member): bool
    {
        $clock = $member->clock();
        $closing = $clock->oldestOpenDayAt($clock->now());
        $closesAt = $clock->closesAt($closing);
        $lead = config()->integer('logralo.push.reminder_lead_hours');

        Context::add('logralo.closing_on', $closing->toDateString());

        if ($clock->now()->lessThan($closesAt->subHours($lead))) {
            Context::add('logralo.reject_reason', 'outside_window');

            return false;
        }

        $atRisk = $this->streaksAtRisk($member, $closing);

        if ($atRisk->isEmpty()) {
            Context::add('logralo.reject_reason', 'nothing_at_risk');

            return false;
        }

        // The sweep runs hourly and the window spans several hours, so the
        // key rather than the schedule is what makes this once a day. Claimed
        // only once there is something to say, and it expires with the window
        // it belongs to.
        if (! Cache::add($this->onceKey($member, $closing), true, $closesAt)) {
            Context::add('logralo.reject_reason', 'already_sent');

            return false;
        }

        Context::add('logralo.goals_at_risk', $atRisk->count());

        $member->notify(new StreakAboutToBreak(
            goalsAtRisk: $atRisk->count(),
            longestStreak: (int) $atRisk->max(),
            closesAt: $closesAt->format('H:i'),
        ));

        return true;
    }

    /**
     * The length of every run that ends if the closing day stays unmarked.
     *
     * Private goals count: this one is only ever sent to the member whose
     * goals they are, and the message names a number rather than a goal.
     *
     * @return Collection<int, int<1, max>>
     */
    private function streaksAtRisk(User $member, CarbonImmutable $closing): Collection
    {
        $goals = $member->activeGoals()->get();
        $histories = $this->history->forGoals($goals);

        return $goals
            ->map(fn (Goal $goal): MarkHistory => $histories->get($goal->id, MarkHistory::empty()))
            ->map(fn (MarkHistory $history): int => $this->streaks->atRiskOn(
                $history->dates(),
                $closing,
                $history->pauses,
            ))
            ->filter(fn (int $days): bool => $days > 0)
            ->values();
    }

    private function onceKey(User $member, CarbonImmutable $closing): string
    {
        return "push-reminders.{$member->id}.{$closing->toDateString()}";
    }
}
