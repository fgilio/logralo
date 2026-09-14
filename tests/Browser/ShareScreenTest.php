<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;

/**
 * The shop window, in a real browser.
 *
 * This one has to run here rather than in tests/Feature: the page ships the
 * right `<html class="dark">` either way, and what broke it was a script
 * taking the class off again after the markup had already landed.
 */
it('keeps the share page dark on a phone that is set to light', function (): void {
    $user = User::factory()->create(['name' => 'Guido']);
    $goal = Goal::factory()->for($user)->create(['name' => 'Gimnasio']);
    $mark = Mark::factory()->for($goal)->create([
        'user_id' => $user->id,
        'marked_on' => now()->toDateString(),
    ]);

    // A stranger out of WhatsApp, on the phone they already had. Nothing here
    // is their choice: the page is written for one palette, over a body that
    // is `bg-zinc-950` whatever the html element ends up saying.
    //
    // The toolbar above it is the same question one layer out. `theme-color`
    // is declared twice and keyed on the phone, so the browser drew a cream
    // bar over the charcoal; the applicable one is read here the way the
    // browser reads it, rather than by counting the tags.
    visit("/l/{$mark->share_token}")->on()->iPhone15Pro()->inLightMode()
        ->assertScript('document.documentElement.classList.contains("dark")')
        ->assertScript(
            'function () {
                return [...document.querySelectorAll(\'meta[name="theme-color"]\')]
                    .filter((meta) => ! meta.media || window.matchMedia(meta.media).matches)
                    .map((meta) => meta.content)
                    .join();
            }',
            '#1a1714',
        )
        ->assertNoJavaScriptErrors();
});
