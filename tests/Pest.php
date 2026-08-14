<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Nothing in this suite may reach the network, shell out, or wait.
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        // Almost every rule in this app is a date boundary, so time stands
        // still unless a test moves it on purpose.
        $this->freezeTime();
    })
    ->in('Arch', 'Feature', 'Unit');

// Browser tests live in their own PHPUnit suite, which `defaultTestSuite` in
// phpunit.xml leaves out of the default run — a CLI `--exclude-group` would
// switch Tia off. The group is kept for ad-hoc `--group=browser` runs.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('browser')
    ->in('Browser');

/*
 * Tia (test impact analysis, Pest 5). `always()` is what activates it without a
 * flag; `locally()` restricts that auto-activation to non-CI environments. Both
 * are spelled out on purpose — `locally()` narrows `always()` rather than
 * implying it.
 *
 * `locally()` is not what keeps Tia out of CI, though: Pest reads the
 * environment from a `--ci` argument and calls everything else local, so the
 * gate jobs pass `--no-tia` themselves. An explicit `--tia` still takes effect
 * on CI, which is how tia-baseline.yml records the shared graph.
 * `filtered()` narrows PHPUnit to the affected files instead of loading every
 * test.
 */
$tia = pest()->tia()
    ->always()
    ->locally()
    ->filtered()
    ->watch([
        'resources/css/**/*.css' => 'tests/Browser',
        'public/build/**/*' => 'tests/Browser',
    ]);

// Fetching the shared baseline shells out to `gh`, and Pest treats a missing
// binary as fatal rather than falling back. A hosted session without the CLI
// records its own graph instead of failing every run on the first command.
if ((new ExecutableFinder)->find('gh') !== null) {
    $tia->baselined();
}
