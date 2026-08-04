pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();
        $this->freezeTime();
    })
    ->in('Arch', 'Feature', 'Unit');

// Tia engine (test impact analysis, Pest 5). `always()` is what activates it
// without a flag; `locally()` restricts that auto-activation to non-CI
// environments (CI is detected via `--ci` or the CI env var), so CI always
// executes the full suite. Spell out both — `locally()` narrows `always()`
// rather than implying it, and `--tia --locally` is documented as the
// equivalent of exactly this pair.
//
// Note this restricts auto-activation only: an explicit `--tia` on the command
// line still takes effect on CI. That is deliberate, and it is what lets the
// tia-baseline workflow record a graph despite `locally()`.
//
// `baselined()` pulls the graph recorded by that workflow (best effort — it
// needs an authenticated `gh`, so sandboxes without one just record their own).
// `filtered()` narrows PHPUnit to the affected test files instead of loading
// every one of them.
pest()->tia()
    ->always()
    ->locally()
    ->baselined()
    ->filtered()
    ->watch([
        'resources/css/**/*.css' => 'tests/Browser',
        'public/build/**/*' => 'tests/Browser',
    ]);
