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

// Tia engine (test impact analysis, Pest 5). `locally()` turns it on for local
// runs and skips it on CI, so CI always executes the full suite. `baselined()`
// pulls the graph recorded by the tia-baseline workflow (best effort — it needs
// an authenticated `gh`, so sandboxes without one just record their own).
// `filtered()` narrows PHPUnit to the affected test files instead of loading
// every one of them.
pest()->tia()
    ->locally()
    ->baselined()
    ->filtered()
    ->watch([
        'resources/css/**/*.css' => 'tests/Browser',
        'public/build/**/*' => 'tests/Browser',
    ]);
