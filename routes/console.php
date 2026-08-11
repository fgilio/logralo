<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * Hourly, not monthly. The group spans timezones, so a month is only over once
 * the last member's grace window on the last day has closed — a moment that
 * does not line up with any single clock. The command itself decides what is
 * ready and is safe to run as often as it likes.
 */
Schedule::command('logralo:close-months')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
