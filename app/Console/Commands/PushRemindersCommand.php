<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SendStreakReminder;
use App\Queries\Members;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Walks the roster looking for members whose grace window is about to shut on
 * a live streak.
 *
 * Hourly, for the same reason the month close is: the window it watches for
 * opens at a different instant for every member, and an hourly sweep needs no
 * cron arithmetic to find it. The Action decides who actually hears anything,
 * and keeps a member from hearing it twice.
 */
#[Signature('logralo:push-reminders')]
#[Description('Nudge members whose streaks break when their grace window closes')]
final class PushRemindersCommand extends Command
{
    public function handle(Members $members, SendStreakReminder $reminder): int
    {
        $sent = 0;

        try {
            foreach ($members->roster() as $member) {
                $sent += $reminder->handle($member) ? 1 : 0;
            }

            Context::add('logralo.reminders_sent', $sent);
            Context::add('logralo.outcome', 'completed');

            $this->components->info($sent === 0 ? 'Nobody to nudge.' : "{$sent} member(s) nudged.");

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('push.sweep.handled');
        }
    }
}
