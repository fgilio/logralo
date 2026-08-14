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
// environments. Spell out both — `locally()` narrows `always()` rather than
// implying it, and `--tia --locally` is documented as the equivalent of exactly
// this pair.
//
// `locally()` is not a CI guard on its own: Pest reads the environment from a
// `--ci` argument, not from the usual variables, and calls everything else
// local. Gate jobs pass `--no-tia` themselves. An explicit `--tia` on the
// command line still takes effect on CI, which is what lets the tia-baseline
// workflow record a graph despite `locally()`.
//
// `filtered()` narrows PHPUnit to the affected test files instead of loading
// every one of them.
$tia = pest()->tia()
    ->always()
    ->locally()
    ->filtered()
    ->watch([
        'resources/css/**/*.css' => 'tests/Browser',
        'public/build/**/*' => 'tests/Browser',
    ]);

// `baselined()` pulls the graph recorded by the tia-baseline workflow, by
// shelling out to GitHub's CLI. Pest treats a missing binary as fatal rather
// than falling back, so a sandbox without `gh` records its own graph instead of
// failing every run on the first command. A failed fetch backs off for 24h
// unless you pass `--refetch`.
if ((new ExecutableFinder)->find('gh') !== null) {
    $tia->baselined();
}
